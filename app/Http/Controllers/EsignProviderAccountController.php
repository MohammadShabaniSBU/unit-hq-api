<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CredentialStatus;
use App\Enums\EsignProvider as EsignProviderName;
use App\Enums\EsignWebhookState;
use App\Http\Resources\EsignProviderAccountResource;
use App\Models\EsignProviderAccount;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialMasker;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\ESign\ESignVerificationException;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\PublicUrlUnreachableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Company-level e-sign credentials (S14-02). One active account per install v1.
 */
class EsignProviderAccountController extends Controller
{
    private const ENTITY = 'esign_provider_account';

    public function __construct(
        private readonly ESignProviderRegistry $registry,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $accounts = EsignProviderAccount::query()->orderBy('id')->get();

        return $this->success([
            'accounts' => EsignProviderAccountResource::collection($accounts)->resolve(),
            'provider_options' => $this->registry->options(),
            'active_provider' => $accounts->first(fn (EsignProviderAccount $a) => $a->is_active)?->provider->value,
        ], 'E-signature settings retrieved successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $providerValues = $this->registry->providers();

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($providerValues)],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $providerValue = $validated['provider'];
        $adapter = $this->registry->make($providerValue, []);
        $fields = $adapter->credentialFields();

        $account = EsignProviderAccount::query()
            ->where('provider', $providerValue)
            ->first();

        /** @var array<string, mixed> $existing */
        $existing = $account !== null
            ? (CredentialMasker::readSafely($account, 'credentials') ?? [])
            : [];

        if (! is_array($existing)) {
            $existing = [];
        }

        $submitted = $validated['credentials'] ?? [];
        $merged = CredentialMasker::mergeFields($existing, $submitted, $fields);

        $hasAnyCredential = CredentialMasker::primarySecret($merged, $fields) !== null
            || collect($fields)->contains(fn (array $meta, string $key) => ! empty($merged[$key]));

        if (! $hasAnyCredential) {
            throw ValidationException::withMessages([
                'credentials' => ['Credentials are required to connect this provider.'],
            ]);
        }

        $isRotate = $account !== null && CredentialMasker::primarySecret($existing, $fields) !== null;

        $verifyAdapter = $this->registry->make($providerValue, $merged);

        try {
            $verifyAdapter->verify();
            $status = CredentialStatus::Connected;
            $lastError = null;
        } catch (ESignVerificationException $e) {
            $status = CredentialStatus::Error;
            $lastError = $e->getMessage();
        }

        $activate = (bool) ($validated['activate'] ?? true);

        $account ??= new EsignProviderAccount([
            'provider' => EsignProviderName::from($providerValue),
            'display_name' => $validated['display_name']
                ?? (EsignProviderName::tryFrom($providerValue)?->label() ?? ucfirst($providerValue)),
        ]);

        if ($activate) {
            EsignProviderAccount::query()
                ->where('is_active', true)
                ->when($account->exists, fn ($q) => $q->where('id', '!=', $account->id))
                ->update(['is_active' => false]);
        }

        $account->fill([
            'display_name' => $validated['display_name'] ?? $account->display_name,
            'credentials' => $merged,
            'is_active' => $activate,
            'status' => $status,
            'last_error' => $lastError,
        ]);
        $account->save();

        $secret = CredentialMasker::primarySecret($merged, $fields);

        if ($isRotate) {
            CredentialAudit::rotated(self::ENTITY, $account, null, $providerValue, $secret, $status->value);
        } else {
            CredentialAudit::created(self::ENTITY, $account, null, $providerValue, $secret, $status->value);
        }

        return $this->success($this->payload(), 'E-signature account saved successfully.');
    }

    public function createWebhook(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = EsignProviderAccount::query()
            ->where('is_active', true)
            ->first();

        if ($account === null || ! $account->isConnected()) {
            throw ValidationException::withMessages([
                'webhook' => ['Connect and activate a provider before creating a webhook.'],
            ]);
        }

        try {
            PublicUrlGuard::assertPublic();
        } catch (PublicUrlUnreachableException $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $adapter = $this->registry->forAccount($account);
        $url = PublicUrlGuard::webhookUrl('api/webhooks/esign/'.$account->getAttributes()['webhook_token']);

        try {
            $ids = $adapter->registerWebhooks($url);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_endpoint_ids = $ids;
        $account->webhook_state = EsignWebhookState::Configured;
        $account->save();

        return $this->success($this->payload(), 'Webhook created successfully.');
    }

    public function destroy(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = EsignProviderAccount::query()
            ->where('is_active', true)
            ->first()
            ?? EsignProviderAccount::query()->orderByDesc('id')->first();

        if ($account === null) {
            return $this->noContent('E-signature account already removed.');
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];
        $fields = $this->registry->make($account->provider->value, [])->credentialFields();
        $secret = CredentialMasker::primarySecret($credentials, $fields);

        $endpointIds = is_array($account->webhook_endpoint_ids) ? $account->webhook_endpoint_ids : [];
        if ($endpointIds !== []) {
            try {
                $this->registry->forAccount($account)->deleteWebhooks(
                    array_map('strval', $endpointIds)
                );
            } catch (\Throwable) {
                // Best-effort.
            }
        }

        $providerValue = $account->provider->value;
        CredentialAudit::removed(self::ENTITY, $account, null, $providerValue, $secret);
        $account->delete();

        return $this->noContent('E-signature account removed successfully.');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $accounts = EsignProviderAccount::query()->orderBy('id')->get();

        return [
            'accounts' => EsignProviderAccountResource::collection($accounts)->resolve(),
            'provider_options' => $this->registry->options(),
            'active_provider' => $accounts->first(fn (EsignProviderAccount $a) => $a->is_active)?->provider->value,
        ];
    }
}
