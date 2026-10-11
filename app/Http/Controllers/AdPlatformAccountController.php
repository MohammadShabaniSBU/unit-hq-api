<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AdPlatformAccountResource;
use App\Models\AdPlatformAccount;
use App\Models\Employee;
use App\Models\SystemEvent;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\AdPlatformException;
use App\Support\Advertising\AdPlatformRegistry;
use App\Support\Advertising\Providers\GoogleAds\GoogleAdsAccount;
use App\Support\Auth\Permission;
use App\Support\Credentials\CredentialAudit;
use App\Support\Credentials\CredentialField;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company-scoped ad platform accounts. Google connects through OAuth.
 * Meta connects through a credential form. Secrets stay masked.
 */
class AdPlatformAccountController extends Controller
{
    private const ENTITY = 'ad_platform_account';

    private const STATE_PREFIX = 'ads.google.oauth.state.';

    private const SELECTION_PREFIX = 'ads.google.oauth.selection.';

    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly AdPlatformRegistry $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        $accounts = AdPlatformAccount::query()
            ->when(! $request->boolean('include_archived'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('id')
            ->get();

        return $this->success(
            AdPlatformAccountResource::collection($accounts)->resolve(),
            'Ad platform accounts retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        if ($request->input('provider') === AdPlatform::GoogleAds->value) {
            throw ValidationException::withMessages([
                'provider' => [__('errors.advertising.google_use_oauth')],
            ]);
        }

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in([AdPlatform::Meta->value])],
            'name' => ['required', 'string', 'max:128'],
            'external_account_id' => ['required', 'string', 'max:64'],
            'credentials' => ['required', 'array'],
            'credentials.system_user_token' => ['required', 'string'],
            'credentials.capi_token' => ['required', 'string'],
            'credentials.pixel_id' => ['required', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $externalId = $this->digits($validated['external_account_id']);
        $pixelId = $this->digits($validated['credentials']['pixel_id']);

        if ($externalId === '') {
            throw ValidationException::withMessages([
                'external_account_id' => [__('errors.advertising.ad_account_token_rejected')],
            ]);
        }

        if ($pixelId === '') {
            throw ValidationException::withMessages([
                'credentials.pixel_id' => [__('errors.advertising.pixel_token_rejected')],
            ]);
        }

        $credentials = [
            'system_user_token' => CredentialField::normalize($validated['credentials']['system_user_token']),
            'capi_token' => CredentialField::normalize($validated['credentials']['capi_token']),
            'pixel_id' => $pixelId,
        ];

        $this->assertAvailable(AdPlatform::Meta, $externalId);

        try {
            $info = $this->registry->make(AdPlatform::Meta, array_merge($credentials, [
                'external_account_id' => $externalId,
            ]))->verify();
        } catch (AdPlatformException $exception) {
            throw $this->validationFromPlatform($exception);
        }

        try {
            $account = DB::transaction(function () use ($validated, $externalId, $credentials, $info): AdPlatformAccount {
                $this->assertAvailable(AdPlatform::Meta, $externalId);

                return AdPlatformAccount::query()->create([
                    'provider' => AdPlatform::Meta,
                    'name' => $validated['name'],
                    'external_account_id' => $externalId,
                    'login_customer_id' => null,
                    'currency' => $info->currency,
                    'timezone' => $info->timezone,
                    'credentials' => $credentials,
                    'is_active' => (bool) ($validated['is_active'] ?? true),
                    'last_verified_at' => now(),
                    'last_error' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'external_account_id' => [__('errors.advertising.duplicate_account')],
            ]);
        }

        $this->auditCreated($account, $credentials);

        return $this->created(
            AdPlatformAccountResource::make($account)->resolve(),
            'Ad platform account created successfully.',
        );
    }

    public function update(Request $request, AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        $this->assertWritable($adPlatformAccount);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'is_active' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string'],
        ]);

        if ($adPlatformAccount->provider === AdPlatform::GoogleAds && $this->submittedSecret($adPlatformAccount, $validated['credentials'] ?? [])) {
            throw ValidationException::withMessages([
                'credentials' => [__('errors.advertising.google_secrets_via_oauth')],
            ]);
        }

        $existing = CredentialMasker::readSafely($adPlatformAccount, 'credentials');
        $existing = is_array($existing) ? $existing : [];
        $fields = $this->credentialFields($adPlatformAccount);
        $merged = CredentialMasker::mergeFields($existing, $validated['credentials'] ?? [], $fields);
        $credentialsChanged = $merged != $existing;
        $secretsChanged = $this->secretsChanged($existing, $merged, $fields);

        if ($credentialsChanged) {
            try {
                $info = $this->registry->make($adPlatformAccount->provider, array_merge($merged, [
                    'external_account_id' => $adPlatformAccount->external_account_id,
                    'login_customer_id' => $adPlatformAccount->login_customer_id,
                    'currency' => $adPlatformAccount->currency,
                ]))->verify();
            } catch (AdPlatformException $exception) {
                throw $this->validationFromPlatform($exception);
            }

            $adPlatformAccount->credentials = $merged;
            $adPlatformAccount->currency = $info->currency;
            $adPlatformAccount->timezone = $info->timezone;
            $adPlatformAccount->last_verified_at = now();
            $adPlatformAccount->last_error = null;
        }

        if (array_key_exists('name', $validated)) {
            $adPlatformAccount->name = $validated['name'];
        }

        if (array_key_exists('is_active', $validated)) {
            $adPlatformAccount->is_active = (bool) $validated['is_active'];
        }

        $adPlatformAccount->save();

        if ($secretsChanged) {
            CredentialAudit::rotated(
                self::ENTITY,
                $adPlatformAccount,
                null,
                $adPlatformAccount->provider->value,
                CredentialMasker::primarySecret($merged, $fields),
                'connected',
            );
        }

        return $this->success(
            AdPlatformAccountResource::make($adPlatformAccount)->resolve(),
            'Ad platform account updated successfully.',
        );
    }

    public function verify(AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        $this->assertWritable($adPlatformAccount);

        try {
            $info = $this->registry->forAccount($adPlatformAccount)->verify();
        } catch (AdPlatformException $exception) {
            $adPlatformAccount->last_error = mb_substr($exception->getMessage(), 0, 255);
            $adPlatformAccount->save();

            SystemEvent::record('ads.account.verify_failed', $adPlatformAccount, [
                'error_class' => $exception::class,
            ]);

            return $this->success(
                AdPlatformAccountResource::make($adPlatformAccount)->resolve(),
                'Ad platform account verification failed.',
            );
        }

        $adPlatformAccount->currency = $info->currency;
        $adPlatformAccount->timezone = $info->timezone;
        $adPlatformAccount->last_verified_at = now();
        $adPlatformAccount->last_error = null;
        $adPlatformAccount->save();

        return $this->success(
            AdPlatformAccountResource::make($adPlatformAccount)->resolve(),
            'Ad platform account verified successfully.',
        );
    }

    public function archive(AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        if ($adPlatformAccount->archived_at === null) {
            $adPlatformAccount->archived_at = now();
            $adPlatformAccount->save();

            $stored = CredentialMasker::readSafely($adPlatformAccount, 'credentials');
            $stored = is_array($stored) ? $stored : [];

            CredentialAudit::removed(
                self::ENTITY,
                $adPlatformAccount,
                null,
                $adPlatformAccount->provider->value,
                CredentialMasker::primarySecret($stored, $this->credentialFields($adPlatformAccount)),
            );
        }

        return $this->success(
            AdPlatformAccountResource::make($adPlatformAccount)->resolve(),
            'Ad platform account archived successfully.',
        );
    }

    public function destroy(AdPlatformAccount $adPlatformAccount): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        return $this->archive($adPlatformAccount);
    }

    public function googleStart(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        try {
            GoogleAdsAccount::assertConfigured();
        } catch (AdPlatformException $exception) {
            throw $this->validationFromPlatform($exception, 'provider');
        }

        $state = Str::random(40);

        Cache::put(self::STATE_PREFIX.$state, [
            'employee_id' => $this->employeeId($request),
        ], now()->addMinutes(self::TTL_MINUTES));

        return $this->success(
            ['url' => GoogleAdsAccount::consentUrl($state)],
            'Google Ads consent URL created successfully.',
        );
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $cached = Cache::pull(self::STATE_PREFIX.$state);

        if (! is_array($cached) || $code === '' || ! isset($cached['employee_id'])) {
            return $this->panelRedirect(['error' => 'oauth_state_invalid']);
        }

        try {
            $tokens = GoogleAdsAccount::exchangeCode($code);
            $customers = GoogleAdsAccount::listAccessibleCustomers($tokens['access_token']);
        } catch (AdPlatformException) {
            return $this->panelRedirect(['error' => 'oauth_exchange_failed']);
        }

        $selection = Str::random(40);

        Cache::put(self::SELECTION_PREFIX.$selection, [
            'employee_id' => (int) $cached['employee_id'],
            'refresh_token' => $tokens['refresh_token'],
            'customers' => $customers,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $this->panelRedirect(['selection' => $selection]);
    }

    public function googleSelection(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        $payload = Cache::get(self::SELECTION_PREFIX.(string) $request->query('selection', ''));

        if (! is_array($payload) || (int) ($payload['employee_id'] ?? 0) !== $this->employeeId($request)) {
            return $this->notFound();
        }

        $customers = [];

        foreach ($payload['customers'] ?? [] as $id) {
            if (is_string($id) && $id !== '') {
                $customers[] = ['id' => $id];
            }
        }

        return $this->success(
            ['customers' => $customers],
            'Google Ads customers retrieved successfully.',
        );
    }

    public function googleComplete(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AdvertisingConnect->value);

        $validated = $request->validate([
            'selection' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:128'],
            'external_account_id' => ['required', 'string', 'max:64'],
            'login_customer_id' => ['nullable', 'string', 'max:32'],
        ]);

        $cacheKey = self::SELECTION_PREFIX.$validated['selection'];
        $payload = Cache::get($cacheKey);

        if (! is_array($payload) || (int) ($payload['employee_id'] ?? 0) !== $this->employeeId($request)) {
            throw ValidationException::withMessages([
                'selection' => [__('errors.advertising.selection_expired')],
            ]);
        }

        $externalId = $this->digits($validated['external_account_id']);
        $customers = is_array($payload['customers'] ?? null) ? $payload['customers'] : [];

        if ($externalId === '' || ! in_array($externalId, $customers, true)) {
            throw ValidationException::withMessages([
                'external_account_id' => [__('errors.advertising.customer_not_accessible')],
            ]);
        }

        $login = $validated['login_customer_id'] ?? null;
        $loginId = is_string($login) && $login !== '' ? $this->digits($login) : null;

        if ($login !== null && $login !== '' && ($loginId === null || $loginId === '')) {
            throw ValidationException::withMessages([
                'login_customer_id' => [__('errors.advertising.customer_not_accessible')],
            ]);
        }

        $refresh = (string) ($payload['refresh_token'] ?? '');

        if ($refresh === '') {
            throw ValidationException::withMessages([
                'selection' => [__('errors.advertising.selection_expired')],
            ]);
        }

        $this->assertAvailable(AdPlatform::GoogleAds, $externalId);

        try {
            $info = $this->registry->make(AdPlatform::GoogleAds, [
                'refresh_token' => $refresh,
                'external_account_id' => $externalId,
                'login_customer_id' => $loginId,
            ])->verify();
        } catch (AdPlatformException $exception) {
            throw $this->validationFromPlatform($exception, 'external_account_id');
        }

        $credentials = ['refresh_token' => $refresh];

        try {
            $account = DB::transaction(function () use ($validated, $externalId, $loginId, $credentials, $info): AdPlatformAccount {
                $this->assertAvailable(AdPlatform::GoogleAds, $externalId);

                return AdPlatformAccount::query()->create([
                    'provider' => AdPlatform::GoogleAds,
                    'name' => $validated['name'],
                    'external_account_id' => $externalId,
                    'login_customer_id' => $loginId,
                    'currency' => $info->currency,
                    'timezone' => $info->timezone,
                    'credentials' => $credentials,
                    'is_active' => true,
                    'last_verified_at' => now(),
                    'last_error' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'external_account_id' => [__('errors.advertising.duplicate_account')],
            ]);
        }

        Cache::forget($cacheKey);

        $this->auditCreated($account, $credentials);

        return $this->created(
            AdPlatformAccountResource::make($account)->resolve(),
            'Ad platform account created successfully.',
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function auditCreated(AdPlatformAccount $account, array $credentials): void
    {
        $fields = $this->credentialFields($account);

        CredentialAudit::created(
            self::ENTITY,
            $account,
            null,
            $account->provider->value,
            CredentialMasker::primarySecret($credentials, $fields),
            'connected',
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function submittedSecret(AdPlatformAccount $account, array $credentials): bool
    {
        foreach ($this->credentialFields($account) as $key => $meta) {
            if (! ($meta['secret'] ?? true)) {
                continue;
            }

            $value = $credentials[$key] ?? null;

            if (is_string($value) && ! CredentialField::isBlank($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $merged
     * @param  array<string, array{label: string, secret: bool}>  $fields
     */
    private function secretsChanged(array $existing, array $merged, array $fields): bool
    {
        foreach ($fields as $key => $meta) {
            if (! ($meta['secret'] ?? true)) {
                continue;
            }

            if (($existing[$key] ?? null) !== ($merged[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{label: string, secret: bool}>
     */
    private function credentialFields(AdPlatformAccount $account): array
    {
        return $this->registry->make($account->provider, [])->credentialFields();
    }

    private function assertWritable(AdPlatformAccount $account): void
    {
        if ($account->archived_at !== null) {
            throw ValidationException::withMessages([
                'account' => [__('errors.advertising.account_archived')],
            ]);
        }
    }

    private function assertAvailable(AdPlatform $provider, string $externalId): void
    {
        $exists = AdPlatformAccount::query()
            ->where('provider', $provider->value)
            ->where('external_account_id', $externalId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'external_account_id' => [__('errors.advertising.duplicate_account')],
            ]);
        }
    }

    private function validationFromPlatform(AdPlatformException $exception, ?string $fallbackField = null): ValidationException
    {
        $field = $exception->field ?? $fallbackField ?? 'credentials';

        return ValidationException::withMessages([
            $field => [$exception->getMessage()],
        ]);
    }

    private function employeeId(Request $request): int
    {
        $user = $request->user();

        if (! $user instanceof Employee) {
            abort(401);
        }

        return (int) $user->getAuthIdentifier();
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @param  array<string, string>  $query
     */
    private function panelRedirect(array $query): RedirectResponse
    {
        $base = rtrim((string) config('app.panel_url'), '/');

        return redirect()->away($base.'/settings/advertising?'.http_build_query($query));
    }
}
