<?php

declare(strict_types=1);

namespace App\Support\ESign;

use App\Enums\ContactChannelType;
use App\Enums\ContractDocumentStatus;
use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Models\EsignProviderAccount;
use App\Models\Interaction;
use App\Models\Setting;
use App\Support\Automation\SubjectChain;
use App\Support\Contracts\ContractSigning;
use App\Support\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Envelope lifecycle: send/resend/cancel + webhook side effects (S14-03).
 */
final class EnvelopeOrchestrator
{
    public function __construct(
        private readonly ESignProviderRegistry $registry,
    ) {}

    /**
     * @throws ValidationException
     */
    public function send(
        Contract $contract,
        ?int $contractDocumentId = null,
        ?CarbonImmutable $expiresAt = null,
        ?Employee $actor = null,
    ): EsignEnvelope {
        $this->assertAwaiting($contract);

        if ($this->liveEnvelope($contract) !== null) {
            throw ValidationException::withMessages([
                'envelope' => [__('errors.esign.live_envelope_exists')],
            ]);
        }

        $document = $this->resolveDraftDocument($contract, $contractDocumentId);
        $account = $this->activeAccount();
        $signer = $this->resolveSigner($contract);
        $pdfBytes = $this->readDocumentPdf($document);

        $expiresAt ??= CarbonImmutable::now()->addDays(
            Setting::leasing()->defaultEsignExpirationDays
        );

        $adapter = $this->registry->forAccount($account);
        $ref = $adapter->createEnvelope(new EnvelopeSpec(
            pdfBytes: $pdfBytes,
            title: 'Contract #'.$contract->id,
            signer: $signer,
            expiresAt: $expiresAt,
            metadata: [
                'contract_id' => $contract->id,
                'contract_document_id' => $document->id,
            ],
        ));

        return DB::transaction(function () use ($contract, $document, $account, $signer, $ref, $expiresAt, $actor): EsignEnvelope {
            $envelope = EsignEnvelope::query()->create([
                'contract_id' => $contract->id,
                'contract_document_id' => $document->id,
                'esign_provider_account_id' => $account->id,
                'provider_envelope_ref' => $ref->providerRef,
                'signer_name' => $signer['name'],
                'signer_email' => $signer['email'],
                'status' => EsignEnvelopeStatus::Sent,
                'expires_at' => $expiresAt,
                'sent_at' => now(),
                'created_by' => $actor?->id,
            ]);

            $document->update([
                'status' => ContractDocumentStatus::Sent,
                'envelope_id' => $envelope->id,
            ]);

            $this->recordSendTimeline($contract, $envelope, $actor);

            RecordsActivity::core('esign.envelope.sent', $contract, [
                'envelope_id' => $envelope->id,
                'contract_document_id' => $document->id,
                'provider_envelope_ref' => $envelope->provider_envelope_ref,
                'signer_email' => $envelope->signer_email,
            ], $actor);

            return $envelope->fresh(['contractDocument', 'esignProviderAccount']) ?? $envelope;
        });
    }

    /**
     * @throws ValidationException
     */
    public function resend(
        Contract $contract,
        EsignEnvelope $envelope,
        ?int $contractDocumentId = null,
        ?CarbonImmutable $expiresAt = null,
        ?Employee $actor = null,
    ): EsignEnvelope {
        $this->assertBelongs($contract, $envelope);
        $this->assertAwaiting($contract);

        if (! $envelope->isLive()) {
            throw ValidationException::withMessages([
                'envelope' => [__('errors.esign.envelope_not_live')],
            ]);
        }

        $this->cancelProviderBestEffort($envelope);
        $envelope->update(['status' => EsignEnvelopeStatus::Cancelled]);

        RecordsActivity::core('esign.envelope.superseded', $contract, [
            'envelope_id' => $envelope->id,
            'provider_envelope_ref' => $envelope->provider_envelope_ref,
        ], $actor);

        $documentId = $contractDocumentId ?? $envelope->contract_document_id;
        $doc = ContractDocument::query()->findOrFail($documentId);

        if ($doc->contract_id !== $contract->id) {
            throw ValidationException::withMessages([
                'contract_document_id' => [__('errors.esign.document_not_draft')],
            ]);
        }

        // Same frozen (sent) document, or a fresh draft.
        if ($doc->status === ContractDocumentStatus::Sent) {
            return $this->sendAgainstDocument($contract, $doc, $expiresAt, $actor, allowSent: true);
        }

        return $this->send($contract, $documentId, $expiresAt, $actor);
    }

    /**
     * @throws ValidationException
     */
    public function cancel(
        Contract $contract,
        EsignEnvelope $envelope,
        ?Employee $actor = null,
    ): EsignEnvelope {
        $this->assertBelongs($contract, $envelope);

        if (! $envelope->isLive()) {
            throw ValidationException::withMessages([
                'envelope' => [__('errors.esign.envelope_not_live')],
            ]);
        }

        $this->cancelLiveEnvelope($envelope, $actor);

        return $envelope->fresh() ?? $envelope;
    }

    /**
     * Best-effort cancel of every live envelope for a contract (contract cancel path).
     */
    public function cancelLiveForContract(Contract $contract, ?Employee $actor = null): void
    {
        $live = EsignEnvelope::query()
            ->where('contract_id', $contract->id)
            ->whereIn('status', [
                EsignEnvelopeStatus::Sent->value,
                EsignEnvelopeStatus::Viewed->value,
            ])
            ->get();

        foreach ($live as $envelope) {
            $this->cancelLiveEnvelope($envelope, $actor);
        }
    }

    public function applyEvent(EsignEnvelope $envelope, ESignEvent $event): void
    {
        match ($event->type) {
            ESignEvent::TYPE_VIEWED => $this->handleViewed($envelope),
            ESignEvent::TYPE_SIGNED => $this->handleSigned($envelope),
            ESignEvent::TYPE_DECLINED => $this->handleDeclined($envelope, $event->declineReason),
            ESignEvent::TYPE_EXPIRED => $this->handleExpired($envelope),
            default => null,
        };
    }

    /**
     * Retry download + completion for envelopes stuck in completion_pending.
     *
     * @return int Number completed or loudly recorded
     */
    public function sweepCompletionPending(): int
    {
        $pending = EsignEnvelope::query()
            ->where('completion_pending', true)
            ->where('status', EsignEnvelopeStatus::Signed->value)
            ->whereNull('signed_pdf_path')
            ->get();

        $count = 0;
        foreach ($pending as $envelope) {
            $this->handleSigned($envelope);
            $count++;
        }

        return $count;
    }

    /**
     * Mark open envelopes past expires_at as expired.
     *
     * @return int Number expired
     */
    public function sweepExpired(): int
    {
        $open = EsignEnvelope::query()
            ->whereIn('status', [
                EsignEnvelopeStatus::Sent->value,
                EsignEnvelopeStatus::Viewed->value,
            ])
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($open as $envelope) {
            $this->handleExpired($envelope);
            $count++;
        }

        return $count;
    }

    private function handleViewed(EsignEnvelope $envelope): void
    {
        if ($envelope->viewed_at !== null) {
            return;
        }

        if (! $envelope->isLive() && $envelope->status !== EsignEnvelopeStatus::Sent) {
            return;
        }

        $envelope->update([
            'status' => EsignEnvelopeStatus::Viewed,
            'viewed_at' => now(),
        ]);

        RecordsActivity::core('esign.envelope.viewed', $envelope->contract, [
            'envelope_id' => $envelope->id,
            'provider_envelope_ref' => $envelope->provider_envelope_ref,
        ]);
    }

    private function handleDeclined(EsignEnvelope $envelope, ?string $reason): void
    {
        if (! $envelope->isLive()) {
            return;
        }

        $envelope->update([
            'status' => EsignEnvelopeStatus::Declined,
            'decline_reason' => $reason,
        ]);

        RecordsActivity::core('esign.envelope.declined', $envelope->contract, [
            'envelope_id' => $envelope->id,
            'decline_reason' => $reason,
        ]);
    }

    private function handleExpired(EsignEnvelope $envelope): void
    {
        if (! $envelope->isLive()) {
            return;
        }

        $envelope->update([
            'status' => EsignEnvelopeStatus::Expired,
        ]);

        RecordsActivity::core('esign.envelope.expired', $envelope->contract, [
            'envelope_id' => $envelope->id,
        ]);
    }

    private function handleSigned(EsignEnvelope $envelope): void
    {
        $envelope->refresh();

        // Already fully completed (artifact + contract).
        if (
            $envelope->status === EsignEnvelopeStatus::Signed
            && $envelope->signed_pdf_path !== null
            && ! $envelope->completion_pending
            && ! $envelope->post_cancellation
        ) {
            return;
        }

        // Already recorded as post-cancellation loud path.
        if ($envelope->post_cancellation && $envelope->signed_pdf_path !== null) {
            return;
        }

        $account = $envelope->esignProviderAccount;
        if ($account === null) {
            return;
        }

        $adapter = $this->registry->forAccount($account);

        try {
            $signed = $adapter->downloadSigned($envelope->provider_envelope_ref);
        } catch (ESignProviderException) {
            $envelope->update([
                'status' => EsignEnvelopeStatus::Signed,
                'signed_at' => $envelope->signed_at ?? now(),
                'completion_pending' => true,
            ]);

            RecordsActivity::core('esign.envelope.completion_pending', $envelope->contract, [
                'envelope_id' => $envelope->id,
            ]);

            return;
        }

        $pdfPath = sprintf(
            'esign/%d/%d/signed.pdf',
            $envelope->contract_id,
            $envelope->id,
        );
        $sha = hash('sha256', $signed->pdfBytes);
        Storage::disk('local')->put($pdfPath, $signed->pdfBytes);

        $certPath = null;
        if ($signed->certificateBytes !== null && $signed->certificateBytes !== '') {
            $certPath = sprintf(
                'esign/%d/%d/certificate.pdf',
                $envelope->contract_id,
                $envelope->id,
            );
            Storage::disk('local')->put($certPath, $signed->certificateBytes);
        }

        $contract = $envelope->contract()->with([
            'items.price',
            'contact',
            'unitItem.item.site.country',
            'unitItem.item.site.legalEntity',
        ])->firstOrFail();

        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if ($status !== ContractStatus::AwaitingSignature) {
            $this->recordPostCancellation($envelope, $pdfPath, $sha, $certPath);

            return;
        }

        try {
            DB::transaction(function () use ($envelope, $contract, $pdfPath, $sha, $certPath): void {
                $envelope->update([
                    'status' => EsignEnvelopeStatus::Signed,
                    'signed_at' => $envelope->signed_at ?? now(),
                    'signed_pdf_path' => $pdfPath,
                    'signed_pdf_sha256' => $sha,
                    'certificate_path' => $certPath,
                    'completion_pending' => false,
                ]);

                ContractDocument::query()
                    ->whereKey($envelope->contract_document_id)
                    ->update(['status' => ContractDocumentStatus::Signed->value]);

                ContractSigning::complete($contract, null, $envelope->created_by);
            });
        } catch (ValidationException) {
            $contract->refresh();
            $statusAfter = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);

            if ($statusAfter !== ContractStatus::AwaitingSignature) {
                $this->recordPostCancellation($envelope, $pdfPath, $sha, $certPath);
            } else {
                throw ValidationException::withMessages([
                    'status' => [__('errors.contracts.transition_conflict')],
                ]);
            }
        }

        RecordsActivity::core('esign.envelope.signed', $contract, [
            'envelope_id' => $envelope->id,
            'signed_pdf_sha256' => $sha,
        ]);
    }

    private function recordPostCancellation(
        EsignEnvelope $envelope,
        string $pdfPath,
        string $sha,
        ?string $certPath,
    ): void {
        $attrs = [
            'status' => EsignEnvelopeStatus::Signed,
            'signed_at' => $envelope->signed_at ?? now(),
            'completion_pending' => false,
            'post_cancellation' => true,
        ];

        if ($envelope->signed_pdf_path === null) {
            $attrs['signed_pdf_path'] = $pdfPath;
            $attrs['signed_pdf_sha256'] = $sha;
            $attrs['certificate_path'] = $certPath;
        }

        $envelope->update($attrs);

        RecordsActivity::core('esign.signed_after_cancellation', $envelope->contract, [
            'envelope_id' => $envelope->id,
            'provider_envelope_ref' => $envelope->provider_envelope_ref,
            'signed_pdf_sha256' => $sha,
            'contract_status' => $envelope->contract->status instanceof ContractStatus
                ? $envelope->contract->status->value
                : (string) $envelope->contract->status,
        ]);
    }

    private function cancelLiveEnvelope(EsignEnvelope $envelope, ?Employee $actor): void
    {
        $this->cancelProviderBestEffort($envelope);

        $envelope->update(['status' => EsignEnvelopeStatus::Cancelled]);

        RecordsActivity::core('esign.envelope.cancelled', $envelope->contract, [
            'envelope_id' => $envelope->id,
            'provider_envelope_ref' => $envelope->provider_envelope_ref,
        ], $actor);
    }

    private function cancelProviderBestEffort(EsignEnvelope $envelope): void
    {
        $account = $envelope->esignProviderAccount;
        if ($account === null) {
            return;
        }

        try {
            $this->registry->forAccount($account)->cancelEnvelope($envelope->provider_envelope_ref);
        } catch (ESignProviderException) {
            // Best-effort: local cancel still proceeds.
        }
    }

    /**
     * @throws ValidationException
     */
    private function sendAgainstDocument(
        Contract $contract,
        ContractDocument $document,
        ?CarbonImmutable $expiresAt,
        ?Employee $actor,
        bool $allowSent = false,
    ): EsignEnvelope {
        if (
            $document->contract_id !== $contract->id
            || (
                $document->status !== ContractDocumentStatus::Draft
                && ! ($allowSent && $document->status === ContractDocumentStatus::Sent)
            )
        ) {
            throw ValidationException::withMessages([
                'contract_document_id' => [__('errors.esign.document_not_draft')],
            ]);
        }

        if ($this->liveEnvelope($contract) !== null) {
            throw ValidationException::withMessages([
                'envelope' => [__('errors.esign.live_envelope_exists')],
            ]);
        }

        $account = $this->activeAccount();
        $signer = $this->resolveSigner($contract);
        $pdfBytes = $this->readDocumentPdf($document);

        $expiresAt ??= CarbonImmutable::now()->addDays(
            Setting::leasing()->defaultEsignExpirationDays
        );

        $adapter = $this->registry->forAccount($account);
        $ref = $adapter->createEnvelope(new EnvelopeSpec(
            pdfBytes: $pdfBytes,
            title: 'Contract #'.$contract->id,
            signer: $signer,
            expiresAt: $expiresAt,
            metadata: [
                'contract_id' => $contract->id,
                'contract_document_id' => $document->id,
            ],
        ));

        return DB::transaction(function () use ($contract, $document, $account, $signer, $ref, $expiresAt, $actor): EsignEnvelope {
            $envelope = EsignEnvelope::query()->create([
                'contract_id' => $contract->id,
                'contract_document_id' => $document->id,
                'esign_provider_account_id' => $account->id,
                'provider_envelope_ref' => $ref->providerRef,
                'signer_name' => $signer['name'],
                'signer_email' => $signer['email'],
                'status' => EsignEnvelopeStatus::Sent,
                'expires_at' => $expiresAt,
                'sent_at' => now(),
                'created_by' => $actor?->id,
            ]);

            $document->update([
                'status' => ContractDocumentStatus::Sent,
                'envelope_id' => $envelope->id,
            ]);

            $this->recordSendTimeline($contract, $envelope, $actor);

            RecordsActivity::core('esign.envelope.sent', $contract, [
                'envelope_id' => $envelope->id,
                'contract_document_id' => $document->id,
                'provider_envelope_ref' => $envelope->provider_envelope_ref,
                'signer_email' => $envelope->signer_email,
                'resend' => true,
            ], $actor);

            return $envelope->fresh(['contractDocument', 'esignProviderAccount']) ?? $envelope;
        });
    }

    private function liveEnvelope(Contract $contract): ?EsignEnvelope
    {
        return EsignEnvelope::query()
            ->where('contract_id', $contract->id)
            ->whereIn('status', [
                EsignEnvelopeStatus::Sent->value,
                EsignEnvelopeStatus::Viewed->value,
            ])
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function assertAwaiting(Contract $contract): void
    {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if ($status !== ContractStatus::AwaitingSignature) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.esign.contract_not_awaiting')],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertBelongs(Contract $contract, EsignEnvelope $envelope): void
    {
        if ($envelope->contract_id !== $contract->id) {
            throw ValidationException::withMessages([
                'envelope' => [__('errors.esign.envelope_mismatch')],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolveDraftDocument(Contract $contract, ?int $documentId): ContractDocument
    {
        if ($documentId !== null) {
            $document = ContractDocument::query()->find($documentId);
            if (
                $document === null
                || $document->contract_id !== $contract->id
                || $document->status !== ContractDocumentStatus::Draft
            ) {
                throw ValidationException::withMessages([
                    'contract_document_id' => [__('errors.esign.document_not_draft')],
                ]);
            }

            return $document;
        }

        $document = ContractDocument::query()
            ->where('contract_id', $contract->id)
            ->where('status', ContractDocumentStatus::Draft->value)
            ->latest('id')
            ->first();

        if ($document === null) {
            throw ValidationException::withMessages([
                'contract_document_id' => [__('errors.esign.document_missing')],
            ]);
        }

        return $document;
    }

    /**
     * @return array{name: string, email: string}
     *
     * @throws ValidationException
     */
    private function resolveSigner(Contract $contract): array
    {
        $contract->loadMissing('contact');
        $contact = $contract->contact;

        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact' => [__('errors.esign.missing_signer_name')],
            ]);
        }

        $name = $this->signerName($contact);
        if ($name === '') {
            throw ValidationException::withMessages([
                'contact' => [__('errors.esign.missing_signer_name')],
            ]);
        }

        $email = SubjectChain::primaryChannel($contact, ContactChannelType::Email)?->value
            ?? (filled($contact->email) ? (string) $contact->email : null);

        if ($email === null || $email === '') {
            throw ValidationException::withMessages([
                'contact' => [__('errors.esign.missing_signer_email')],
            ]);
        }

        return ['name' => $name, 'email' => $email];
    }

    private function signerName(Contact $contact): string
    {
        if (filled($contact->billing_name)) {
            return trim((string) $contact->billing_name);
        }

        return trim(trim((string) $contact->first_name).' '.trim((string) $contact->last_name));
    }

    /**
     * @throws ValidationException
     */
    private function readDocumentPdf(ContractDocument $document): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($document->pdf_path)) {
            throw ValidationException::withMessages([
                'contract_document_id' => [__('errors.esign.document_pdf_missing')],
            ]);
        }

        return (string) $disk->get($document->pdf_path);
    }

    /**
     * @throws ValidationException
     */
    private function activeAccount(): EsignProviderAccount
    {
        $account = EsignProviderAccount::query()
            ->where('is_active', true)
            ->where('status', CredentialStatus::Connected)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'esign' => [__('errors.esign.no_provider_account')],
            ]);
        }

        return $account;
    }

    private function recordSendTimeline(Contract $contract, EsignEnvelope $envelope, ?Employee $actor): void
    {
        $contract->loadMissing('contact');
        if ($contract->contact === null) {
            return;
        }

        Interaction::query()->create([
            'contact_id' => $contract->contact_id,
            'deal_id' => $contract->deal_id,
            'channel' => 'other',
            'direction' => 'outbound',
            'occurred_at' => now(),
            'summary' => 'E-signature envelope sent',
            'content' => 'Sent contract document for signature to '.$envelope->signer_email,
            'metadata' => [
                'type' => 'esign_envelope',
                'envelope_id' => $envelope->id,
                'contract_id' => $contract->id,
                'provider_envelope_ref' => $envelope->provider_envelope_ref,
                'actor_id' => $actor?->id,
            ],
        ]);
    }
}
