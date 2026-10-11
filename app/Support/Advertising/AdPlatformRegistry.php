<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use App\Models\AdPlatformAccount as AdPlatformAccountModel;
use App\Support\Advertising\Contracts\AdPlatformAccount;
use App\Support\Advertising\Contracts\CapturesClickIds;
use App\Support\Advertising\Contracts\ListsCampaigns;
use App\Support\Advertising\Contracts\ListsConversionTargets;
use App\Support\Advertising\Contracts\ReportsConversions;
use App\Support\Advertising\Contracts\ReportsSpend;
use App\Support\Advertising\Providers\GoogleAds\GoogleAdsAccount;
use App\Support\Advertising\Providers\Meta\MetaAccount;
use App\Support\Credentials\CredentialMasker;
use InvalidArgumentException;

/**
 * Provider → adapter class. Capability is interface presence, never a map.
 */
final class AdPlatformRegistry
{
    /** @var array<string, class-string<AdPlatformAccount>> */
    private array $map;

    public function __construct()
    {
        $this->map = [
            AdPlatform::GoogleAds->value => GoogleAdsAccount::class,
            AdPlatform::Meta->value => MetaAccount::class,
        ];
    }

    /**
     * @return class-string<AdPlatformAccount>
     */
    public function adapterClass(AdPlatform $platform): string
    {
        $class = $this->map[$platform->value] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException('No ad adapter registered for '.$platform->value.'.');
        }

        return $class;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    /**
     * @param  class-string<AdPlatformAccount>  $class
     */
    public function register(AdPlatform $platform, string $class): void
    {
        $this->map[$platform->value] = $class;
    }

    public function make(AdPlatform $platform, array $credentials): AdPlatformAccount
    {
        $class = $this->adapterClass($platform);

        return $class::make($credentials);
    }

    public function forAccount(AdPlatformAccountModel $account): AdPlatformAccount
    {
        $stored = CredentialMasker::readSafely($account, 'credentials');

        if (! is_array($stored)) {
            if (CredentialMasker::isUnreadable($account, 'credentials')) {
                throw new AdPlatformException(
                    __('errors.advertising.credentials_unreadable'),
                    false,
                    field: 'credentials',
                );
            }

            $stored = [];
        }

        return $this->make($account->provider, array_merge($stored, [
            'external_account_id' => $account->external_account_id,
            'login_customer_id' => $account->login_customer_id,
            'currency' => $account->currency,
        ]));
    }

    public function reportsConversions(AdPlatform $platform): bool
    {
        return $this->implements($platform, ReportsConversions::class);
    }

    public function reportsSpend(AdPlatform $platform): bool
    {
        return $this->implements($platform, ReportsSpend::class);
    }

    public function listsCampaigns(AdPlatform $platform): bool
    {
        return $this->implements($platform, ListsCampaigns::class);
    }

    public function listsConversionTargets(AdPlatform $platform): bool
    {
        return $this->implements($platform, ListsConversionTargets::class);
    }

    public function capturesClickIds(AdPlatform $platform): bool
    {
        return $this->implements($platform, CapturesClickIds::class);
    }

    /**
     * @param  class-string  $interface
     */
    private function implements(AdPlatform $platform, string $interface): bool
    {
        $class = $this->map[$platform->value] ?? null;

        return $class !== null && is_subclass_of($class, $interface);
    }
}
