<?php

declare(strict_types=1);

namespace App\Support\Country;

use App\Models\Country;
use App\Models\DeploymentIdentity;
use App\Support\RecordsActivity;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of CountryProfile implementations. Country-dependent behaviour
 * is read from here — never from operator settings (D9 / invariant 73).
 */
final class CountryProfiles
{
    /** @var array<string, CountryProfile> */
    private static array $resolved = [];

    /**
     * @return array<string, class-string<CountryProfile>>
     */
    public static function map(): array
    {
        return [
            EsProfile::CODE => EsProfile::class,
            FrProfile::CODE => FrProfile::class,
            GbProfile::CODE => GbProfile::class,
        ];
    }

    /** @return Array<int, CountryProfile> */
    public static function all(): array
    {
        $profiles = [];
        foreach (array_keys(self::map()) as $code) {
            $profiles[] = self::for($code);
        }

        return $profiles;
    }

    public static function configuredCode(): string
    {
        $code = strtoupper(trim((string) config('deployment.country', '')));

        if ($code === '') {
            throw UnknownCountryException::missing();
        }

        if (! array_key_exists($code, self::map())) {
            throw UnknownCountryException::unknown($code);
        }

        return $code;
    }

    public static function assertConfigured(): void
    {
        self::configuredCode();
    }

    public static function for(string $code): CountryProfile
    {
        $code = strtoupper($code);

        if (isset(self::$resolved[$code])) {
            return self::$resolved[$code];
        }

        $class = self::map()[$code] ?? null;
        if ($class === null) {
            throw UnknownCountryException::unknown($code);
        }

        return self::$resolved[$code] = new $class;
    }

    public static function current(): CountryProfile
    {
        $identity = self::identity();

        if ($identity !== null) {
            return self::for($identity->country_code);
        }

        return self::for(self::configuredCode());
    }

    public static function identity(): ?DeploymentIdentity
    {
        if (! self::tableReady()) {
            return null;
        }

        return DeploymentIdentity::instance();
    }

    public static function isLocked(): bool
    {
        return self::identity()?->isLocked() === true;
    }

    public static function isLockedMismatch(): bool
    {
        $identity = self::identity();
        if ($identity === null || ! $identity->isLocked()) {
            return false;
        }

        try {
            return $identity->country_code !== self::configuredCode();
        } catch (UnknownCountryException) {
            return true;
        }
    }

    /**
     * First boot writes country_code from env. Before lock, a changed env
     * updates the row. After lock the row is never rewritten.
     */
    public static function syncIdentity(): void
    {
        if (! self::tableReady()) {
            return;
        }

        $envCode = self::configuredCode();
        $identity = DeploymentIdentity::instance();

        if ($identity === null) {
            DeploymentIdentity::query()->create([
                'id' => DeploymentIdentity::SINGLETON_ID,
                'country_code' => $envCode,
                'locked_at' => null,
            ]);

            return;
        }

        if ($identity->isLocked()) {
            return;
        }

        if ($identity->country_code === $envCode) {
            return;
        }

        $from = $identity->country_code;
        $identity->update(['country_code' => $envCode]);

        RecordsActivity::core('deployment.country_changed', null, [
            'from' => $from,
            'to' => $envCode,
        ], anonymous: true);
    }

    public static function lock(): void
    {
        if (! self::tableReady()) {
            return;
        }

        self::syncIdentity();

        $identity = DeploymentIdentity::instance();
        if ($identity === null || $identity->isLocked()) {
            return;
        }

        $identity->update(['locked_at' => now()]);

        RecordsActivity::core('deployment.country_locked', null, [
            'country_code' => $identity->country_code,
        ], anonymous: true);
    }

    public static function countryId(): int
    {
        $code = self::current()->code();
        $id = Country::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new UnknownCountryException(
                "countries row for {$code} is missing. Run CountrySeeder.",
            );
        }

        return (int) $id;
    }

    /**
     * @return array{country: string, currency: string, default_locale: string, allowed_timezones: Array<int, string>, tax_subdivisions: Array<int, string>, payment_rails: Array<int, string>, fiscal_regime: string}
     */
    public static function apiPayload(): array
    {
        $profile = self::current();

        return [
            'country' => $profile->code(),
            'currency' => $profile->currency(),
            'default_locale' => $profile->defaultLocale(),
            'allowed_timezones' => $profile->allowedTimezones(),
            'tax_subdivisions' => $profile->taxSubdivisions(),
            'payment_rails' => $profile->paymentRails(),
            'fiscal_regime' => $profile->fiscalRegime(),
        ];
    }

    public static function resetResolved(): void
    {
        self::$resolved = [];
    }

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('deployment_identity');
        } catch (\Throwable) {
            return false;
        }
    }
}
