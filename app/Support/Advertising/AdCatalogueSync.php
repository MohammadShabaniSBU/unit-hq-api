<?php

declare(strict_types=1);

namespace App\Support\Advertising;

use App\Models\AdCampaign;
use App\Models\AdPlatformAccount;
use App\Models\AdSpendDaily;
use App\Models\SystemEvent;
use App\Support\Advertising\Contracts\ListsCampaigns;
use App\Support\Advertising\Contracts\ReportsSpend;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls campaign catalogue rows and trailing daily spend for one ad account.
 * A failure is recorded on that account and does not stop the others.
 */
final class AdCatalogueSync
{
    public function __construct(private readonly AdPlatformRegistry $registry) {}

    public function clampDays(int $days): int
    {
        return max(1, min(366, $days));
    }

    public function syncCampaigns(AdPlatformAccount $account): void
    {
        $this->locked('ads-sync-campaigns:'.$account->id, $account, 'campaigns', function () use ($account): void {
            $this->writeCampaigns($account);
        });
    }

    public function syncSpend(AdPlatformAccount $account, int $days): void
    {
        $this->locked('ads-sync-spend:'.$account->id, $account, 'spend', function () use ($account, $days): void {
            $this->writeSpend($account, $days);
        });
    }

    /**
     * @param  callable(): void  $write
     */
    private function locked(string $key, AdPlatformAccount $account, string $kind, callable $write): void
    {
        $lock = Cache::lock($key, 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $write();
        } catch (Throwable $exception) {
            $this->markFailed($account, $kind, $exception);
        } finally {
            $this->release($lock);
        }
    }

    private function writeCampaigns(AdPlatformAccount $account): void
    {
        $this->started($account, 'campaigns');

        $adapter = $this->registry->forAccount($account);

        if (! $adapter instanceof ListsCampaigns) {
            $this->committed($account, 'campaigns', 0);

            return;
        }

        $rows = [];

        foreach ($adapter->campaigns() as $row) {
            if ($row->externalId !== '') {
                $rows[] = $row;
            }
        }

        $now = now();
        $count = 0;

        DB::transaction(function () use ($account, $rows, $now, &$count): void {
            foreach ($rows as $row) {
                $campaign = AdCampaign::query()->firstOrNew([
                    'ad_platform_account_id' => $account->id,
                    'external_id' => $row->externalId,
                ]);

                $campaign->name = $row->name;
                $campaign->status = $row->status;
                $campaign->channel_type = $row->channelType;
                $campaign->last_synced_at = $now;

                if (! $campaign->exists) {
                    $campaign->first_seen_at = $now;
                }

                $campaign->save();
                $count++;
            }
        });

        $this->clearError($account);
        $this->committed($account, 'campaigns', $count);
    }

    /**
     * Upsert spend inside the trailing window. This is the documented exception
     * to append-only facts (decision A8): ad_spend_daily caches an external
     * system's numbers. Dates outside the window are never rewritten.
     */
    private function writeSpend(AdPlatformAccount $account, int $days): void
    {
        $days = $this->clampDays($days);
        $this->started($account, 'spend');

        $adapter = $this->registry->forAccount($account);

        if (! $adapter instanceof ReportsSpend) {
            $this->committed($account, 'spend', 0);

            return;
        }

        $today = $this->today($account);
        $from = $today->subDays($days);
        $fromDate = $from->toDateString();
        $toDate = $today->toDateString();
        $stored = strtoupper((string) $account->currency);
        $reported = strtoupper($adapter->reportedCurrency());

        if ($stored === '' || $reported !== $stored) {
            throw new AdPlatformException(__('errors.advertising.currency_mismatch'), false);
        }

        $incoming = [];

        foreach ($adapter->spend($from, $today) as $row) {
            if ($row->spendDate < $fromDate || $row->spendDate > $toDate) {
                continue;
            }

            if (strtoupper($row->currency) !== $stored) {
                throw new AdPlatformException(__('errors.advertising.currency_mismatch'), false);
            }

            $incoming[] = $row;
        }

        $campaigns = AdCampaign::query()
            ->where('ad_platform_account_id', $account->id)
            ->get()
            ->keyBy('external_id');

        $writable = [];
        $skipped = 0;

        foreach ($incoming as $row) {
            if ($campaigns->get($row->externalCampaignId) === null) {
                $skipped++;

                continue;
            }

            $writable[] = $row;
        }

        $written = 0;
        $syncedAt = now();

        DB::transaction(function () use ($writable, $campaigns, $syncedAt, &$written): void {
            foreach ($writable as $row) {
                $campaign = $campaigns->get($row->externalCampaignId);

                if (! $campaign instanceof AdCampaign) {
                    continue;
                }

                AdSpendDaily::query()->updateOrCreate(
                    [
                        'ad_campaign_id' => $campaign->id,
                        'spend_date' => $row->spendDate,
                    ],
                    [
                        'impressions' => $row->impressions,
                        'clicks' => $row->clicks,
                        'cost' => $row->cost,
                        'currency' => strtoupper($row->currency),
                        'synced_at' => $syncedAt,
                    ],
                );
                $written++;
            }
        });

        $this->clearError($account);
        $this->committed($account, 'spend', $written, $skipped);
    }

    private function today(AdPlatformAccount $account): CarbonImmutable
    {
        $timezone = $account->timezone;

        if (! is_string($timezone) || $timezone === '') {
            throw new AdPlatformException(__('errors.advertising.timezone_missing'), false);
        }

        try {
            return CarbonImmutable::now($timezone)->startOfDay();
        } catch (Throwable) {
            throw new AdPlatformException(__('errors.advertising.timezone_missing'), false);
        }
    }

    private function started(AdPlatformAccount $account, string $kind): void
    {
        SystemEvent::record('ads.sync.started', $account, [
            'account_id' => $account->id,
            'kind' => $kind,
        ]);
    }

    private function committed(AdPlatformAccount $account, string $kind, int $count, int $skipped = 0): void
    {
        $payload = [
            'account_id' => $account->id,
            'kind' => $kind,
            'count' => $count,
        ];

        if ($kind === 'spend') {
            $payload['skipped'] = $skipped;
        }

        SystemEvent::record('ads.sync.committed', $account, $payload);
    }

    private function markFailed(AdPlatformAccount $account, string $kind, Throwable $exception): void
    {
        $message = $exception->getMessage();
        $account->last_error = mb_substr($message, 0, 255);
        $account->save();

        SystemEvent::record('ads.sync.failed', $account, [
            'account_id' => $account->id,
            'kind' => $kind,
            'reason' => str_contains($message, 'currency_mismatch') ? 'currency_mismatch' : $exception::class,
        ]);
    }

    private function clearError(AdPlatformAccount $account): void
    {
        if ($account->last_error === null) {
            return;
        }

        $account->last_error = null;
        $account->save();
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // The lock expired or was already released.
        }
    }
}
