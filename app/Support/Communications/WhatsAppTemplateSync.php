<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\CommunicationAccount;
use App\Models\WhatsappTemplate;
use App\Support\Communications\Contracts\ManagesWhatsAppTemplates;
use App\Support\Communications\Results\TemplateStatusSnapshot;
use App\Support\Credentials\CredentialMasker;

/**
 * Applies provider-authoritative WhatsApp template status onto local rows.
 * Poll is authoritative; webhook is latency.
 */
final class WhatsAppTemplateSync
{
    /**
     * Poll every active WhatsApp account that supports template management.
     *
     * @return int Number of local rows updated
     */
    public function pollAll(): int
    {
        $updated = 0;
        $accounts = CommunicationAccount::query()
            ->where('channel', Channel::Whatsapp)
            ->where('is_active', true)
            ->get();

        foreach ($accounts as $account) {
            $updated += $this->pollAccount($account);
        }

        return $updated;
    }

    public function pollAccount(CommunicationAccount $account): int
    {
        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $adapter = app(ProviderRegistry::class)->make(
            $account->channel,
            $account->provider,
            $credentials,
        );

        if (! $adapter instanceof ManagesWhatsAppTemplates) {
            return 0;
        }

        $updated = 0;
        foreach ($adapter->listNonTerminalStatuses() as $snapshot) {
            if ($this->apply($account->id, $snapshot)) {
                $updated++;
            }
        }

        // Also fetch individually for submitted locals missing from list (belt/braces).
        $submitted = WhatsappTemplate::query()
            ->where('communication_account_id', $account->id)
            ->where('status', WhatsappTemplate::STATUS_SUBMITTED)
            ->whereNotNull('provider_template_id')
            ->get();

        foreach ($submitted as $row) {
            try {
                $snapshot = $adapter->fetchStatus((string) $row->provider_template_id);
            } catch (\Throwable) {
                continue;
            }
            if ($this->apply($account->id, $snapshot)) {
                $updated++;
            }
        }

        return $updated;
    }

    public function apply(int $accountId, TemplateStatusSnapshot $snapshot): bool
    {
        if ($snapshot->providerTemplateId === '' || $snapshot->status === '') {
            return false;
        }

        $query = WhatsappTemplate::query()->where('communication_account_id', $accountId);

        $row = (clone $query)
            ->where('provider_template_id', $snapshot->providerTemplateId)
            ->first();

        if ($row === null && $snapshot->name !== null && $snapshot->language !== null) {
            $row = (clone $query)
                ->where('name', $snapshot->name)
                ->where('language', $snapshot->language)
                ->whereIn('status', [
                    WhatsappTemplate::STATUS_SUBMITTED,
                    WhatsappTemplate::STATUS_APPROVED,
                ])
                ->first();
        }

        if ($row === null) {
            return false;
        }

        $newStatus = $snapshot->status;
        if (! in_array($newStatus, [
            WhatsappTemplate::STATUS_SUBMITTED,
            WhatsappTemplate::STATUS_APPROVED,
            WhatsappTemplate::STATUS_REJECTED,
            WhatsappTemplate::STATUS_REVOKED,
        ], true)) {
            return false;
        }

        $providerIdNeedsStamp = $row->provider_template_id === null
            || $row->provider_template_id !== $snapshot->providerTemplateId;

        if (
            $row->status === $newStatus
            && ($snapshot->rejectionReason === null || $row->rejection_reason === $snapshot->rejectionReason)
            && ! $providerIdNeedsStamp
        ) {
            return false;
        }

        $terminal = in_array($newStatus, [
            WhatsappTemplate::STATUS_APPROVED,
            WhatsappTemplate::STATUS_REJECTED,
            WhatsappTemplate::STATUS_REVOKED,
        ], true);

        $rejection = $row->rejection_reason;
        if ($newStatus === WhatsappTemplate::STATUS_REJECTED) {
            $rejection = $snapshot->rejectionReason;
        } elseif ($newStatus === WhatsappTemplate::STATUS_APPROVED) {
            $rejection = null;
        }

        $row->forceFill([
            'status' => $newStatus,
            'provider_template_id' => $snapshot->providerTemplateId,
            'rejection_reason' => $rejection,
            'decided_at' => $terminal ? ($row->decided_at ?? now()) : $row->decided_at,
        ])->save();

        return true;
    }
}
