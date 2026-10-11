<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\AdCampaign;
use App\Models\AdPlatformAccount;
use App\Models\Deal;
use App\Models\MarketingTouch;
use App\Models\Site;
use App\Support\Advertising\AdCatalogueSync;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\AdPlatformRegistry;
use App\Support\Advertising\AttributedTouch;
use App\Support\Advertising\Providers\Fake\FakeGoogleAdsAccount;
use App\Support\Advertising\Providers\Fake\FakeMetaAccount;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\Crowd\DemoRng;
use Illuminate\Support\Collection;

/**
 * One Google account and one Meta account, synced through fake adapters.
 * Touches land on a share of recent deals so paid acquisition is not empty.
 * No network. No conversion upload.
 */
final class AdvertisingDemo
{
    public static function installFakes(): void
    {
        $registry = new AdPlatformRegistry;
        $registry->register(AdPlatform::GoogleAds, FakeGoogleAdsAccount::class);
        $registry->register(AdPlatform::Meta, FakeMetaAccount::class);
        app()->instance(AdPlatformRegistry::class, $registry);
    }

    public static function apply(?DemoWorld $world = null): void
    {
        self::installFakes();

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()->orderBy('id')->get()->values();
        $googleSite = $sites->get(0);
        if (! $googleSite instanceof Site) {
            return;
        }

        $metaSite = $sites->get(1);
        if (! $metaSite instanceof Site) {
            $metaSite = $googleSite;
        }

        $google = self::account($googleSite, AdPlatform::GoogleAds, FakeGoogleAdsAccount::EXTERNAL_ID, 'Demo Google Ads');
        $meta = self::account($metaSite, AdPlatform::Meta, FakeMetaAccount::EXTERNAL_ID, 'Demo Meta');

        $sync = app(AdCatalogueSync::class);
        if ($google instanceof AdPlatformAccount) {
            $sync->syncCampaigns($google);
            $sync->syncSpend($google, 7);
            self::map($google, $googleSite);
        }
        if ($meta instanceof AdPlatformAccount) {
            $sync->syncCampaigns($meta);
            $sync->syncSpend($meta, 7);
            self::map($meta, $metaSite);
        }

        self::touches($world);
    }

    private static function account(Site $site, AdPlatform $provider, string $externalId, string $name): ?AdPlatformAccount
    {
        $currency = strtoupper((string) $site->currency);
        $timezone = (string) $site->timezone;
        if (strlen($currency) !== 3 || $timezone === '') {
            return null;
        }

        $account = AdPlatformAccount::query()->firstOrNew([
            'provider' => $provider,
            'external_account_id' => $externalId,
        ]);
        $account->fill([
            'name' => $name,
            'currency' => $currency,
            'timezone' => $timezone,
            'credentials' => ['refresh_token' => 'demo'],
            'is_active' => true,
            'last_verified_at' => now(),
            'last_error' => null,
        ]);
        $account->save();

        return $account;
    }

    private static function map(AdPlatformAccount $account, Site $site): void
    {
        AdCampaign::query()
            ->where('ad_platform_account_id', $account->id)
            ->orderBy('id')
            ->each(function (AdCampaign $campaign) use ($site): void {
                $campaign->site_id = $site->id;
                $campaign->utm_campaign_hint = $campaign->external_id;
                if ($campaign->mapped_at === null) {
                    $campaign->mapped_at = now();
                }
                $campaign->save();
            });
    }

    private static function touches(?DemoWorld $world): void
    {
        $rng = DemoRng::fromEnv();
        $since = now()->subDays(AttributedTouch::DEFAULT_WINDOW_DAYS);

        $query = Deal::query()
            ->whereNotNull('contact_id')
            ->whereNotNull('site_id')
            ->where('created_at', '>=', $since)
            ->orderBy('id');

        if ($world !== null && $world->has('crowd.count')) {
            $ids = self::crowdContactIds($world);
            if ($ids === []) {
                return;
            }
            $query->whereIn('contact_id', $ids);
        }

        $deals = $query->get();
        if ($deals->isEmpty()) {
            return;
        }

        /** @var Collection<int, AdCampaign> $campaigns */
        $campaigns = AdCampaign::query()->with('account')->orderBy('id')->get();
        if ($campaigns->isEmpty()) {
            return;
        }

        $chosen = [];
        foreach ($deals as $deal) {
            if ($rng->bool(0.5)) {
                $chosen[] = $deal;
            }
        }
        if ($chosen === []) {
            $first = $deals->first();
            if ($first instanceof Deal) {
                $chosen[] = $first;
            }
        }

        foreach ($chosen as $deal) {
            $campaign = self::campaignFor($campaigns, $rng->bool() ? AdPlatform::GoogleAds : AdPlatform::Meta);
            if (! $campaign instanceof AdCampaign || $campaign->account === null) {
                continue;
            }

            $hint = $campaign->utm_campaign_hint ?? $campaign->external_id;
            $already = MarketingTouch::query()
                ->where('contact_id', $deal->contact_id)
                ->where('utm_campaign', $hint)
                ->exists();
            if ($already) {
                continue;
            }

            $platform = $campaign->account->provider;
            $anchor = CarbonImmutable::parse($deal->created_at);

            MarketingTouch::query()->create([
                'contact_id' => $deal->contact_id,
                'occurred_at' => $anchor->subDay(),
                'site_id' => $deal->site_id,
                'platform' => $platform,
                'utm_source' => $platform === AdPlatform::Meta ? 'facebook' : 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => $hint,
                'click_ids' => $platform === AdPlatform::Meta
                    ? ['fbclid' => 'demo-fbclid-'.$deal->contact_id]
                    : ['gclid' => 'demo-gclid-'.$deal->contact_id],
                'consent_snapshot' => [
                    'ad_storage' => true,
                    'ad_user_data' => true,
                    'ad_personalization' => true,
                ],
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private static function crowdContactIds(DemoWorld $world): array
    {
        $ids = [];
        $count = (int) $world->get('crowd.count');
        for ($i = 0; $i < $count; $i++) {
            $handle = 'crowd_'.$i.'.contact';
            if (! $world->has($handle)) {
                continue;
            }
            $ids[] = $world->contact($handle)->id;
        }

        return $ids;
    }

    /**
     * @param  Collection<int, AdCampaign>  $campaigns
     */
    private static function campaignFor(Collection $campaigns, AdPlatform $platform): ?AdCampaign
    {
        $match = $campaigns->first(function (AdCampaign $campaign) use ($platform): bool {
            return $campaign->account?->provider === $platform;
        });

        if ($match instanceof AdCampaign) {
            return $match;
        }

        $fallback = $campaigns->first();

        return $fallback instanceof AdCampaign ? $fallback : null;
    }
}
