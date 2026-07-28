<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facility;

use App\Enums\CommunicationAccountScope;
use App\Enums\CommunicationProviderType;
use App\Enums\CredentialStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommunicationAccountResource;
use App\Models\CommunicationAccount;
use App\Support\Communications\Providers\CommunicationProviderException;
use App\Support\Communications\Providers\CommunicationProviderResolver;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialField;
use App\Support\Credentials\CredentialMasker;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\PublicUrlUnreachableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Company-level (scope=company) communication provider credentials.
 * Site-scoped accounts share the same model/table but ship no UI yet.
 */
class CommunicationAccountController extends Controller
{
    private const ENTITY = 'communication_account';

    public function index(): JsonResponse
    {
        $accounts = CommunicationAccount::query()
            ->where('scope', CommunicationAccountScope::Company)
            ->get()
            ->keyBy(fn (CommunicationAccount $account) => $account->provider_type->value);

        $rows = collect(CommunicationProviderType::cases())
            ->map(fn (CommunicationProviderType $type) => $accounts->get($type->value) ?? new CommunicationAccount([
                'scope' => CommunicationAccountScope::Company,
                'provider_type' => $type,
                'status' => CredentialStatus::Disconnected,
            ]));

        return $this->success(
            CommunicationAccountResource::collection($rows),
            'Communication accounts retrieved successfully.'
        );
    }

    public function update(Request $request, CommunicationProviderType $providerType): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string'],
        ]);

        $account = CommunicationAccount::query()
            ->where('scope', CommunicationAccountScope::Company)
            ->where('provider_type', $providerType->value)
            ->first();

        $submittedKey = $validated['api_key'] ?? null;

        if (CredentialField::isBlank($submittedKey)) {
            if ($account === null) {
                throw ValidationException::withMessages([
                    'api_key' => ['An API key is required to connect this provider.'],
                ]);
            }

            // Blank submitted field = unchanged, never wipe.
            return $this->success(
                CommunicationAccountResource::make($account),
                'Communication account unchanged.'
            );
        }

        $submittedKey = CredentialField::normalize($submittedKey);
        $isRotate = $account !== null && CredentialMasker::readSafely($account, 'api_key') !== null;

        $adapter = CommunicationProviderResolver::resolve($providerType);

        try {
            $adapter->verifyCredentials($submittedKey);
            $status = CredentialStatus::Connected;
            $lastError = null;
        } catch (CommunicationProviderException $e) {
            $status = CredentialStatus::Error;
            $lastError = $e->getMessage();
        }

        $account ??= new CommunicationAccount([
            'scope' => CommunicationAccountScope::Company,
            'provider_type' => $providerType,
        ]);

        $account->fill([
            'api_key' => $submittedKey,
            'status' => $status,
            'verified_at' => $status === CredentialStatus::Connected ? now() : null,
            'last_error' => $lastError,
        ]);
        $account->save();

        if ($isRotate) {
            CredentialAudit::rotated(self::ENTITY, $account, null, $providerType->value, $submittedKey, $status->value);
        } else {
            CredentialAudit::created(self::ENTITY, $account, null, $providerType->value, $submittedKey, $status->value);
        }

        return $this->success(
            CommunicationAccountResource::make($account),
            'Communication account saved successfully.'
        );
    }

    public function createWebhook(CommunicationProviderType $providerType): JsonResponse
    {
        $account = CommunicationAccount::query()
            ->where('scope', CommunicationAccountScope::Company)
            ->where('provider_type', $providerType->value)
            ->first();

        $apiKey = $account !== null ? CredentialMasker::readSafely($account, 'api_key') : null;

        if ($account === null || $apiKey === null) {
            throw ValidationException::withMessages([
                'api_key' => ['Connect an API key before creating a webhook.'],
            ]);
        }

        try {
            PublicUrlGuard::assertPublic();
        } catch (PublicUrlUnreachableException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_url_token ??= Str::random(40);
        $url = PublicUrlGuard::webhookUrl("api/webhooks/brevo/{$account->webhook_url_token}");

        $adapter = CommunicationProviderResolver::resolve($providerType);

        try {
            $providerWebhookId = $adapter->registerWebhook($apiKey, $url);
        } catch (CommunicationProviderException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_provider_id = $providerWebhookId;
        $account->webhook_configured_at = now();
        $account->save();

        return $this->success(
            CommunicationAccountResource::make($account),
            'Webhook created successfully.'
        );
    }

    public function destroy(CommunicationProviderType $providerType): JsonResponse
    {
        $account = CommunicationAccount::query()
            ->where('scope', CommunicationAccountScope::Company)
            ->where('provider_type', $providerType->value)
            ->first();

        if ($account === null) {
            return $this->noContent('Communication account already removed.');
        }

        $apiKey = CredentialMasker::readSafely($account, 'api_key');

        if ($account->webhook_provider_id !== null && $apiKey !== null) {
            $adapter = CommunicationProviderResolver::resolve($providerType);

            try {
                $adapter->removeWebhook($apiKey, $account->webhook_provider_id);
            } catch (CommunicationProviderException) {
                // Best-effort: still remove the local record so the operator
                // isn't stuck with a credential they can't rotate.
            }
        }

        $account->delete();

        CredentialAudit::removed(self::ENTITY, null, null, $providerType->value, $apiKey);

        return $this->noContent('Communication account removed successfully.');
    }
}
