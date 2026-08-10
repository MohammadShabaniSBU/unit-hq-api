<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiProvider;
use App\Enums\CredentialStatus;
use App\Http\Resources\AiProviderAccountResource;
use App\Models\AiProviderAccount;
use App\Models\Employee;
use App\Support\Ai\AiProviderRegistry;
use App\Support\Auth\Permission;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company-level AI provider credentials restricting which models the
 * Copilot may use. Archive-only; at most one non-archived default.
 */
class AiProviderAccountController extends Controller
{
    private const ENTITY = 'ai_provider_account';

    public function __construct(
        private readonly AiProviderRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $includeArchived = $request->boolean('include_archived');

        $accounts = AiProviderAccount::query()
            ->when(! $includeArchived, fn ($q) => $q->active())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return $this->success(
            AiProviderAccountResource::collection($accounts)->resolve(),
            'AI provider accounts retrieved successfully.'
        );
    }

    public function providers(): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        return $this->success(
            $this->registry->options(),
            'AI providers retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($this->registry->providers())],
            'display_name' => ['required', 'string', 'max:128'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $providerValue = $validated['provider'];
        $adapter = $this->registry->make($providerValue, []);
        $fields = $adapter->credentialFields();

        $merged = CredentialMasker::mergeFields([], $validated['credentials'] ?? [], $fields);

        $verifyAdapter = $this->registry->make($providerValue, $merged);
        $verification = $verifyAdapter->verify();

        if ($verification->ok) {
            $status = CredentialStatus::Connected;
            $lastError = null;
            $lastVerifiedAt = now();
            // Nothing restricted yet — everything discovered is allowed by
            // default; the operator opts specific models out afterward.
            $allowedModels = $verification->availableModels;
            $defaultModel = $allowedModels[0] ?? null;
        } else {
            $status = CredentialStatus::Error;
            $lastError = $verification->error;
            $lastVerifiedAt = null;
            $allowedModels = [];
            $defaultModel = null;
        }

        $makeDefault = (bool) ($validated['is_default'] ?? false);

        $account = DB::transaction(function () use (
            $validated,
            $providerValue,
            $merged,
            $status,
            $lastError,
            $lastVerifiedAt,
            $allowedModels,
            $defaultModel,
            $makeDefault,
            $request,
        ): AiProviderAccount {
            if ($makeDefault) {
                AiProviderAccount::query()
                    ->active()
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return AiProviderAccount::query()->create([
                'provider' => AiProvider::from($providerValue),
                'display_name' => $validated['display_name'],
                'credentials' => $merged,
                'allowed_models' => $allowedModels,
                'default_model' => $defaultModel,
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
            AiProviderAccountResource::make($account)->resolve(),
            'AI provider account created successfully.'
        );
    }

    public function update(Request $request, AiProviderAccount $aiProviderAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $validated = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:128'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string'],
            'allowed_models' => ['sometimes', 'array'],
            'allowed_models.*' => ['string'],
            'default_model' => ['sometimes', 'nullable', 'string'],
        ]);

        $providerValue = $aiProviderAccount->provider->value;
        $fields = $this->registry->make($providerValue, [])->credentialFields();

        /** @var array<string, mixed> $existing */
        $existing = CredentialMasker::readSafely($aiProviderAccount, 'credentials') ?? [];
        if (! is_array($existing)) {
            $existing = [];
        }

        $submitted = $validated['credentials'] ?? [];
        $merged = CredentialMasker::mergeFields($existing, $submitted, $fields);

        $allowedModels = array_key_exists('allowed_models', $validated)
            ? array_values($validated['allowed_models'])
            : ($aiProviderAccount->allowed_models ?? []);

        $defaultModel = array_key_exists('default_model', $validated)
            ? $validated['default_model']
            : $aiProviderAccount->default_model;

        if ($defaultModel !== null && ! in_array($defaultModel, $allowedModels, true)) {
            throw ValidationException::withMessages([
                'default_model' => ['The default model must be one of the allowed models.'],
            ]);
        }

        $secretChanged = false;

        foreach ($fields as $key => $meta) {
            if (! ($meta['secret'] ?? true) || ! array_key_exists($key, $submitted)) {
                continue;
            }

            $value = $submitted[$key];

            if (is_string($value) && $value !== '' && ($existing[$key] ?? null) !== $value) {
                $secretChanged = true;
                break;
            }
        }

        $aiProviderAccount->fill([
            'display_name' => $validated['display_name'] ?? $aiProviderAccount->display_name,
            'credentials' => $merged,
            'allowed_models' => $allowedModels,
            'default_model' => $defaultModel,
        ]);
        $aiProviderAccount->save();

        if ($secretChanged) {
            $secret = CredentialMasker::primarySecret($merged, $fields);
            CredentialAudit::rotated(
                self::ENTITY,
                $aiProviderAccount,
                null,
                $providerValue,
                $secret,
                $aiProviderAccount->connection_status->value,
            );
        }

        return $this->success(
            AiProviderAccountResource::make($aiProviderAccount->fresh())->resolve(),
            'AI provider account updated successfully.'
        );
    }

    public function verify(AiProviderAccount $aiProviderAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if ($aiProviderAccount->isArchived()) {
            throw ValidationException::withMessages([
                'ai_provider_account' => ['This account is archived.'],
            ]);
        }

        $adapter = $this->registry->forAccount($aiProviderAccount);
        $verification = $adapter->verify();

        if ($verification->ok) {
            $aiProviderAccount->fill([
                'connection_status' => CredentialStatus::Connected,
                'last_error' => null,
                'last_verified_at' => now(),
            ]);
        } else {
            $aiProviderAccount->fill([
                'connection_status' => CredentialStatus::Error,
                'last_error' => $verification->error,
            ]);
        }

        $aiProviderAccount->save();

        return $this->success([
            'status' => $aiProviderAccount->connection_status->value,
            'last_error' => $aiProviderAccount->last_error,
            'last_verified_at' => $aiProviderAccount->last_verified_at?->toDateTimeString(),
            // Freshly discovered models are returned but never silently
            // merged into allowed_models — that would undo a deliberate
            // restriction (e.g. re-enabling a model the operator excluded).
            'available_models' => $verification->availableModels,
        ], 'AI provider account verification completed.');
    }

    public function setDefault(AiProviderAccount $aiProviderAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if ($aiProviderAccount->isArchived()) {
            throw ValidationException::withMessages([
                'ai_provider_account' => ['This account is archived.'],
            ]);
        }

        if ($aiProviderAccount->default_model === null) {
            throw ValidationException::withMessages([
                'ai_provider_account' => ['Choose a default model for this account before making it the default.'],
            ]);
        }

        DB::transaction(function () use ($aiProviderAccount): void {
            AiProviderAccount::query()
                ->active()
                ->where('is_default', true)
                ->whereKeyNot($aiProviderAccount->id)
                ->update(['is_default' => false]);

            $aiProviderAccount->update(['is_default' => true]);
        });

        return $this->success(
            AiProviderAccountResource::make($aiProviderAccount->fresh())->resolve(),
            'Default AI provider account updated successfully.'
        );
    }

    public function archive(AiProviderAccount $aiProviderAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $this->archiveAccount($aiProviderAccount);

        return $this->success(
            AiProviderAccountResource::make($aiProviderAccount->fresh())->resolve(),
            'AI provider account archived successfully.'
        );
    }

    public function unarchive(AiProviderAccount $aiProviderAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        if (! $aiProviderAccount->isArchived()) {
            throw ValidationException::withMessages([
                'ai_provider_account' => ['This account is not archived.'],
            ]);
        }

        if ($aiProviderAccount->is_default) {
            $hasOtherDefault = AiProviderAccount::query()
                ->active()
                ->where('is_default', true)
                ->exists();

            if ($hasOtherDefault) {
                $aiProviderAccount->is_default = false;
            }
        }

        $aiProviderAccount->archived_at = null;
        $aiProviderAccount->save();

        return $this->success(
            AiProviderAccountResource::make($aiProviderAccount->fresh())->resolve(),
            'AI provider account unarchived successfully.'
        );
    }

    public function destroy(AiProviderAccount $aiProviderAccount): JsonResponse
    {
        Gate::authorize(Permission::CredentialManage->value);

        $this->archiveAccount($aiProviderAccount);

        return $this->success(
            AiProviderAccountResource::make($aiProviderAccount->fresh())->resolve(),
            'AI provider account archived successfully.'
        );
    }

    private function archiveAccount(AiProviderAccount $account): void
    {
        if ($account->isArchived()) {
            throw ValidationException::withMessages([
                'ai_provider_account' => ['This account is already archived.'],
            ]);
        }

        /** @var array<string, mixed> $credentials */
        $credentials = CredentialMasker::readSafely($account, 'credentials') ?? [];
        $credentials = is_array($credentials) ? $credentials : [];

        $fields = $this->registry->supports($account->provider->value)
            ? $this->registry->make($account->provider->value, [])->credentialFields()
            : [];

        $secret = CredentialMasker::primarySecret($credentials, $fields);

        $account->update(['archived_at' => now(), 'is_default' => false]);

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

        return $user instanceof Employee ? $user->id : null;
    }
}
