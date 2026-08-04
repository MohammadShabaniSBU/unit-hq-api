<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\CredentialStatus;
use App\Http\Resources\AccessProviderAccountResource;
use App\Models\AccessEvent;
use App\Models\AccessProviderAccount;
use App\Support\Access\AccessProviderException;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\AccessVerificationException;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialMasker;
use App\Support\Http\PublicUrlGuard;
use App\Support\Http\PublicUrlUnreachableException;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Company-level access-control credentials (S15-01). One active account per install v1.
 */
class AccessProviderAccountController extends Controller
{
    private const ENTITY = 'access_provider_account';

    public function __construct(
        private readonly AccessProviderRegistry $registry,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        return $this->success($this->payload(), 'Access control settings retrieved successfully.');
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

        $account = AccessProviderAccount::query()
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
        } catch (AccessVerificationException $e) {
            $status = CredentialStatus::Error;
            $lastError = $e->getMessage();
        }

        $activate = (bool) ($validated['activate'] ?? true);

        $account ??= new AccessProviderAccount([
            'provider' => AccessProviderName::from($providerValue),
            'display_name' => $validated['display_name']
                ?? (AccessProviderName::tryFrom($providerValue)?->label() ?? ucfirst($providerValue)),
        ]);

        if ($activate) {
            AccessProviderAccount::query()
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

        return $this->success($this->payload(), 'Access control account saved successfully.');
    }

    public function createWebhook(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = AccessProviderAccount::query()
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
        $url = PublicUrlGuard::webhookUrl('api/webhooks/access/'.$account->getAttributes()['webhook_token']);

        try {
            $ids = $adapter->registerWebhooks($url);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['webhook' => [$e->getMessage()]]);
        }

        $account->webhook_endpoint_ids = $ids;
        $account->webhook_state = AccessWebhookState::Configured;
        $account->save();

        return $this->success($this->payload(), 'Webhook created successfully.');
    }

    public function refreshPoints(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = AccessProviderAccount::query()
            ->where('is_active', true)
            ->first();

        if ($account === null || ! $account->isConnected()) {
            throw ValidationException::withMessages([
                'points' => ['Connect and activate a provider before refreshing points.'],
            ]);
        }

        try {
            $points = $this->registry->forAccount($account)->listPoints();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['points' => [$e->getMessage()]]);
        }

        $account->discovered_points = array_map(
            fn ($p) => $p->toArray(),
            $points,
        );
        $account->points_discovered_at = now();
        $account->save();

        return $this->success($this->payload(), 'Discovered points refreshed successfully.');
    }

    /**
     * Human-only revoke of a provider grant we did not place (S15-02 posture).
     */
    public function revokeUnknownGrant(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $validated = $request->validate([
            'grant_ref' => ['required', 'string', 'max:128'],
        ]);

        $account = AccessProviderAccount::query()
            ->where('is_active', true)
            ->first();

        if ($account === null || ! $account->isConnected()) {
            throw ValidationException::withMessages([
                'grant_ref' => ['Connect and activate a provider before revoking grants.'],
            ]);
        }

        $attention = is_array($account->sync_attention) ? $account->sync_attention : [];
        $unknown = is_array($attention['unknown_grants'] ?? null)
            ? array_values($attention['unknown_grants'])
            : [];

        $ref = $validated['grant_ref'];
        $known = collect($unknown)->contains(
            fn ($row): bool => is_array($row) && (string) ($row['grant_ref'] ?? '') === $ref,
        );

        if (! $known) {
            throw ValidationException::withMessages([
                'grant_ref' => ['Unknown grant is not on the attention list.'],
            ]);
        }

        try {
            $this->registry->forAccount($account)->revoke($ref);
        } catch (AccessProviderException $e) {
            throw ValidationException::withMessages(['grant_ref' => [$e->getMessage()]]);
        }

        $attention['unknown_grants'] = array_values(array_filter(
            $unknown,
            fn ($row): bool => ! (is_array($row) && (string) ($row['grant_ref'] ?? '') === $ref),
        ));
        $account->forceFill(['sync_attention' => $attention])->save();

        RecordsActivity::core('access.unknown_grant.revoked', $account, [
            'grant_ref' => $ref,
        ]);

        return $this->success($this->payload(), 'Unknown grant revoked successfully.');
    }

    public function destroy(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $account = AccessProviderAccount::query()
            ->where('is_active', true)
            ->first()
            ?? AccessProviderAccount::query()->orderByDesc('id')->first();

        if ($account === null) {
            return $this->noContent('Access control account already removed.');
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

        return $this->noContent('Access control account removed successfully.');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $accounts = AccessProviderAccount::query()->orderBy('id')->get();
        $active = $accounts->first(fn (AccessProviderAccount $a) => $a->is_active);

        return [
            'accounts' => AccessProviderAccountResource::collection($accounts)->resolve(),
            'provider_options' => $this->registry->options(),
            'active_provider' => $active?->provider->value,
            'attention' => AccessEvent::attentionCounts(),
        ];
    }
}
