<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnalyticsProvider;
use App\Enums\CredentialStatus;
use App\Http\Resources\AnalyticsAccountResource;
use App\Models\AnalyticsAccount;
use App\Models\Employee;
use App\Support\Auth\Permission;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialMasker;
use App\Support\Insights\AnalyticsProviderRegistry;
use App\Support\Insights\IframeHostGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company-level analytics provider credentials (S21-02).
 * Archive-only; at most one non-archived default.
 */
class AnalyticsAccountController extends Controller
{
    private const ENTITY = 'analytics_account';

    public function __construct(
        private readonly AnalyticsProviderRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $includeArchived = $request->boolean('include_archived');

        $accounts = AnalyticsAccount::query()
            ->when(! $includeArchived, fn ($q) => $q->active())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return $this->success(
            AnalyticsAccountResource::collection($accounts)->resolve(),
            'Analytics accounts retrieved successfully.'
        );
    }

    public function providers(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        return $this->success(
            $this->registry->options(),
            'Analytics providers retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $providerValues = $this->registry->providers();

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($providerValues)],
            'display_name' => ['required', 'string', 'max:128'],
            'base_url' => ['required', 'string', 'max:255'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $providerValue = $validated['provider'];
        $adapter = $this->registry->make($providerValue, [], $validated['base_url']);
        $fields = $adapter->credentialFields();

        if ($providerValue === AnalyticsProvider::Iframe->value) {
            IframeHostGuard::assertAllowed($validated['base_url']);
        }

        $submitted = $validated['credentials'] ?? [];
        $merged = $providerValue === AnalyticsProvider::Iframe->value
            ? []
            : CredentialMasker::mergeFields([], $submitted, $fields);

        if ($providerValue === AnalyticsProvider::Metabase->value) {
            $hasApiKey = is_string($merged['api_key'] ?? null) && $merged['api_key'] !== '';
            $hasEmbedKey = is_string($merged['embedding_secret_key'] ?? null) && $merged['embedding_secret_key'] !== '';

            if (! $hasApiKey || ! $hasEmbedKey) {
                throw ValidationException::withMessages([
                    'credentials' => ['API key and embedding secret key are required.'],
                ]);
            }
        }

        $verifyAdapter = $this->registry->make($providerValue, $merged, $validated['base_url']);
        $verification = $verifyAdapter->verify();

        if ($verification->ok) {
            $status = CredentialStatus::Connected;
            $lastError = null;
            $lastVerifiedAt = now();
        } else {
            $status = CredentialStatus::Error;
            $lastError = $verification->error;
            $lastVerifiedAt = null;
        }

        $makeDefault = (bool) ($validated['is_default'] ?? false);

        $account = DB::transaction(function () use (
            $validated,
            $providerValue,
            $merged,
            $status,
            $lastError,
            $lastVerifiedAt,
            $makeDefault,
            $request,
        ): AnalyticsAccount {
            if ($makeDefault) {
                AnalyticsAccount::query()
                    ->active()
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return AnalyticsAccount::query()->create([
                'provider' => AnalyticsProvider::from($providerValue),
                'display_name' => $validated['display_name'],
                'base_url' => $validated['base_url'],
                'credentials' => $merged,
                'is_default' => $makeDefault,
                'connection_status' => $status,
                'last_error' => $lastError,
                'last_verified_at' => $lastVerifiedAt,
                'created_by' => $this->resolveCreatedBy($request),
            ]);
        });

        $secret = CredentialMasker::primarySecret($merged, $fields);
        CredentialAudit::created(
            self::ENTITY,
            $account,
            null,
            $providerValue,
            $secret,
            $status->value,
        );

        return $this->created(
            AnalyticsAccountResource::make($account)->resolve(),
            'Analytics account created successfully.'
        );
    }

    public function update(Request $request, AnalyticsAccount $analyticsAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:128'],
            'base_url' => ['sometimes', 'string', 'max:255'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string'],
        ]);

        $providerValue = $analyticsAccount->provider->value;
        $adapter = $this->registry->make($providerValue, [], $analyticsAccount->base_url);
        $fields = $adapter->credentialFields();

        $baseUrl = $validated['base_url'] ?? $analyticsAccount->base_url;

        if ($providerValue === AnalyticsProvider::Iframe->value) {
            IframeHostGuard::assertAllowed($baseUrl);
        }

        /** @var array<string, mixed> $existing */
        $existing = CredentialMasker::readSafely($analyticsAccount, 'credentials') ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }

        $submitted = $validated['credentials'] ?? [];
        $merged = $providerValue === AnalyticsProvider::Iframe->value
            ? []
            : CredentialMasker::mergeFields($existing, $submitted, $fields);

        $secretChanged = false;

        foreach ($fields as $key => $meta) {
            if (! ($meta['secret'] ?? true)) {
                continue;
            }

            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            $value = $submitted[$key];

            if (is_string($value) && $value !== '' && ($existing[$key] ?? null) !== $value) {
                $secretChanged = true;
                break;
            }
        }

        $analyticsAccount->fill([
            'display_name' => $validated['display_name'] ?? $analyticsAccount->display_name,
            'base_url' => $baseUrl,
            'credentials' => $merged,
        ]);
        $analyticsAccount->save();

        if ($secretChanged) {
            $secret = CredentialMasker::primarySecret($merged, $fields);
            CredentialAudit::rotated(
                self::ENTITY,
                $analyticsAccount,
                null,
                $providerValue,
                $secret,
                $analyticsAccount->connection_status->value,
            );
        }

        return $this->success(
            AnalyticsAccountResource::make($analyticsAccount->fresh())->resolve(),
            'Analytics account updated successfully.'
        );
    }

    public function verify(AnalyticsAccount $analyticsAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if ($analyticsAccount->isArchived()) {
            throw ValidationException::withMessages([
                'analytics_account' => [__('errors.insights.already_archived')],
            ]);
        }

        if ($analyticsAccount->provider === AnalyticsProvider::Iframe) {
            IframeHostGuard::assertAllowed($analyticsAccount->base_url);
        }

        $adapter = $this->registry->forAccount($analyticsAccount);
        $verification = $adapter->verify();

        if ($verification->ok) {
            $analyticsAccount->fill([
                'connection_status' => CredentialStatus::Connected,
                'last_error' => null,
                'last_verified_at' => now(),
            ]);
        } else {
            $analyticsAccount->fill([
                'connection_status' => CredentialStatus::Error,
                'last_error' => $verification->error,
            ]);
        }

        $analyticsAccount->save();

        return $this->success([
            'status' => $analyticsAccount->connection_status->value,
            'last_error' => $analyticsAccount->last_error,
            'last_verified_at' => $analyticsAccount->last_verified_at?->toDateTimeString(),
        ], 'Analytics account verification completed.');
    }

    public function setDefault(AnalyticsAccount $analyticsAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if ($analyticsAccount->isArchived()) {
            throw ValidationException::withMessages([
                'analytics_account' => [__('errors.insights.already_archived')],
            ]);
        }

        DB::transaction(function () use ($analyticsAccount): void {
            AnalyticsAccount::query()
                ->active()
                ->where('is_default', true)
                ->whereKeyNot($analyticsAccount->id)
                ->update(['is_default' => false]);

            $analyticsAccount->update(['is_default' => true]);
        });

        return $this->success(
            AnalyticsAccountResource::make($analyticsAccount->fresh())->resolve(),
            'Default analytics account updated successfully.'
        );
    }

    public function archive(AnalyticsAccount $analyticsAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $this->archiveAccount($analyticsAccount);

        return $this->success(
            AnalyticsAccountResource::make($analyticsAccount->fresh())->resolve(),
            'Analytics account archived successfully.'
        );
    }

    public function unarchive(AnalyticsAccount $analyticsAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if (! $analyticsAccount->isArchived()) {
            throw ValidationException::withMessages([
                'analytics_account' => [__('errors.insights.not_archived')],
            ]);
        }

        if ($analyticsAccount->is_default) {
            $hasOtherDefault = AnalyticsAccount::query()
                ->active()
                ->where('is_default', true)
                ->exists();

            if ($hasOtherDefault) {
                $analyticsAccount->is_default = false;
            }
        }

        $analyticsAccount->archived_at = null;
        $analyticsAccount->save();

        return $this->success(
            AnalyticsAccountResource::make($analyticsAccount->fresh())->resolve(),
            'Analytics account unarchived successfully.'
        );
    }

    public function destroy(AnalyticsAccount $analyticsAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $this->archiveAccount($analyticsAccount);

        return $this->success(
            AnalyticsAccountResource::make($analyticsAccount->fresh())->resolve(),
            'Analytics account archived successfully.'
        );
    }

    private function archiveAccount(AnalyticsAccount $account): void
    {
        if ($account->isArchived()) {
            throw ValidationException::withMessages([
                'analytics_account' => [__('errors.insights.already_archived')],
            ]);
        }

        if ($account->is_default && $account->hasLiveReports()) {
            throw ValidationException::withMessages([
                'analytics_account' => [__('errors.insights.archive_has_live_reports')],
            ]);
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $fields = $this->registry->supports($account->provider->value)
            ? $this->registry->make($account->provider->value, [], $account->base_url)->credentialFields()
            : [];

        $secret = CredentialMasker::primarySecret($credentials, $fields);

        $account->update(['archived_at' => now()]);

        CredentialAudit::removed(
            self::ENTITY,
            $account,
            null,
            $account->provider->value,
            $secret,
        );
    }

    private function resolveCreatedBy(Request $request): ?int
    {
        $user = $request->user();

        if ($user instanceof Employee) {
            return $user->id;
        }

        return null;
    }
}
