<?php

declare(strict_types=1);

namespace App\Support\Advertising\Providers\Fake;

use App\Support\Advertising\AdCampaignStatus;
use App\Support\Advertising\Contracts\AdPlatformAccount;
use App\Support\Advertising\Contracts\ListsCampaigns;
use App\Support\Advertising\Contracts\ReportsSpend;
use App\Support\Advertising\Results\AccountInfo;
use App\Support\Advertising\Results\CampaignRow;
use App\Support\Advertising\Results\SpendRow;
use Carbon\CarbonImmutable;

/**
 * Demo catalogue for Google Ads. No HTTP.
 */
final class FakeGoogleAdsAccount implements AdPlatformAccount, ListsCampaigns, ReportsSpend
{
    public const EXTERNAL_ID = 'demo-google-search';

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function __construct(private readonly array $credentials) {}

    public static function make(array $credentials): self
    {
        return new self($credentials);
    }

    public function credentialFields(): array
    {
        return [
            'refresh_token' => ['label' => 'Refresh token', 'secret' => true],
        ];
    }

    public function verify(): AccountInfo
    {
        return new AccountInfo(
            currency: $this->currency(),
            timezone: $this->timezone(),
        );
    }

    public function campaigns(): iterable
    {
        yield new CampaignRow(self::EXTERNAL_ID, 'Demo brand search', AdCampaignStatus::Enabled, 'search');
    }

    public function reportedCurrency(): string
    {
        return $this->currency();
    }

    public function spend(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $currency = $this->currency();
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();

        while ($cursor->lte($end)) {
            yield new SpendRow(self::EXTERNAL_ID, $cursor->toDateString(), '18.40', 1200, 40, $currency);
            $cursor = $cursor->addDay();
        }
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->credentials['currency'] ?? ''));
    }

    private function timezone(): string
    {
        $timezone = $this->credentials['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
