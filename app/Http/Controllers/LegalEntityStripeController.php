<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CredentialStatus;
use App\Http\Resources\PaymentProviderAccountResource;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialField;
use App\Support\Credentials\CredentialMasker;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\PublicUrlUnreachableException;
use App\Support\Payments\StripeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

/**
 * Per-entity Stripe credentials — legal entity is the merchant of record
 * (architecture-payments-and-fiscal.md §2 / S06-00).
 */
class LegalEntityStripeController extends Controller
{
    private const ENTITY = 'payment_provider_account';

    private const PROVIDER = 'stripe';

    public function __construct(
        private readonly StripeClient $stripe,
    ) {}

    public function show(LegalEntity $legalEntity): JsonResponse
    {
        $account = $this->activeAccount($legalEntity) ?? new PaymentProviderAccount([
            'legal_entity_id' => $legalEntity->id,
            'provider' => self::PROVIDER,
            'display_name' => 'Stripe',
            'status' => CredentialStatus::Disconnected,
            'is_active' => true,
        ]);

        return $this->success(
            PaymentProviderAccountResource::make($account),
            'Stripe settings retrieved successfully.'
        );
    }

    public function update(Request $request, LegalEntity $legalEntity): JsonResponse
    {
        $validated = $request->validate([
            'publishable_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string'],
        ]);

        $account = $this->activeAccount($legalEntity) ?? new PaymentProviderAccount([
            'legal_entity_id' => $legalEntity->id,
            'provider' => self::PROVIDER,
            'display_name' => 'Stripe',
            'is_active' => true,
        ]);

        $isRotate = $account->exists && CredentialMasker::readSafely($account, 'secret_key') !== null;
        $previousProviderAccountId = $account->provider_account_id;

        if (array_key_exists('publishable_key', $validated)) {
            $account->publishable_key = $validated['publishable_key'];
        }

        if (CredentialField::isBlank($validated['secret_key'] ?? null)) {
            if (! $account->exists) {
                throw ValidationException::withMessages([
                    'secret_key' => ['A secret key is required to connect Stripe.'],
                ]);
            }

            $account->save();

            return $this->success(
                PaymentProviderAccountResource::make($account->fresh()),
                'Stripe settings saved successfully.'
            );
        }

        $submittedSecret = CredentialField::normalize($validated['secret_key']);
        $providerAccountId = $previousProviderAccountId;
        $mismatch = false;

        try {
            $this->stripe->verifyBalance($submittedSecret);
            $retrieved = $this->stripe->retrieveAccount($submittedSecret);
            $providerAccountId = $retrieved['id'];

            if (
                $previousProviderAccountId !== null
                && $previousProviderAccountId !== ''
                && $previousProviderAccountId !== $providerAccountId
            ) {
                $mismatch = true;
            }

            $status = CredentialStatus::Connected;
            $lastError = null;
        } catch (ApiErrorException $e) {
            $status = CredentialStatus::Error;
            $lastError = $e->getMessage();
            $mismatch = false;
        }

        $account->secret_key = $submittedSecret;
        $account->status = $status;
        $account->last_error = $lastError;

        if ($status === CredentialStatus::Connected) {
            $account->provider_account_id = $providerAccountId;
        }

        $account->save();

        if ($isRotate) {
            CredentialAudit::rotated(self::ENTITY, $legalEntity, null, self::PROVIDER, $submittedSecret, $status->value);
        } else {
            CredentialAudit::created(self::ENTITY, $legalEntity, null, self::PROVIDER, $submittedSecret, $status->value);
        }

        $fresh = $account->fresh() ?? $account;
        $fresh->providerAccountMismatch = $mismatch;

        return $this->success(
            PaymentProviderAccountResource::make($fresh),
            'Stripe settings saved successfully.'
        );
    }

    public function createWebhook(LegalEntity $legalEntity): JsonResponse
    {
        $account = $this->activeAccount($legalEntity);
        $secretKey = $account !== null ? CredentialMasker::readSafely($account, 'secret_key') : null;

        if ($account === null || $secretKey === null) {
            throw ValidationException::withMessages([
                'secret_key' => ['Connect a Stripe secret key before creating a webhook.'],
            ]);
        }

        try {
            PublicUrlGuard::assertPublic();
        } catch (PublicUrlUnreachableException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        // Ensure token exists before building the URL (creating event covers new rows).
        if ($account->account_token === null || $account->account_token === '') {
            $account->account_token = \Illuminate\Support\Str::random(40);
            $account->save();
        }

        $url = PublicUrlGuard::webhookUrl("api/webhooks/stripe/{$account->account_token}");

        try {
            $endpoint = $this->stripe->createWebhookEndpoint($secretKey, $url, [
                'payment_intent.succeeded',
                'payment_intent.payment_failed',
                'charge.refunded',
                'setup_intent.succeeded',
            ]);
        } catch (ApiErrorException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_endpoint_id = $endpoint['id'];
        $account->webhook_secret = $endpoint['secret'];
        $account->save();

        return $this->success(
            PaymentProviderAccountResource::make($account->fresh()),
            'Stripe webhook created successfully.'
        );
    }

    public function destroy(LegalEntity $legalEntity): JsonResponse
    {
        $account = $this->activeAccount($legalEntity);

        if ($account === null) {
            return $this->noContent('Stripe settings already removed.');
        }

        $secretKey = CredentialMasker::readSafely($account, 'secret_key');

        if ($account->webhook_endpoint_id !== null && $secretKey !== null) {
            try {
                $this->stripe->deleteWebhookEndpoint($secretKey, $account->webhook_endpoint_id);
            } catch (ApiErrorException) {
                // Best-effort: still remove the local record.
            }
        }

        $account->delete();

        CredentialAudit::removed(self::ENTITY, $legalEntity, null, self::PROVIDER, $secretKey);

        return $this->noContent('Stripe settings removed successfully.');
    }

    public function publicKey(LegalEntity $legalEntity): JsonResponse
    {
        if ($legalEntity->isArchived()) {
            return $this->notFound('Legal entity not found.');
        }

        $account = $this->activeAccount($legalEntity);

        return $this->success([
            'legal_entity_id' => $legalEntity->id,
            'publishable_key' => $account?->publishable_key,
        ], 'Stripe public key retrieved successfully.');
    }

    private function activeAccount(LegalEntity $legalEntity): ?PaymentProviderAccount
    {
        return PaymentProviderAccount::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('provider', self::PROVIDER)
            ->where('is_active', true)
            ->first();
    }
}
