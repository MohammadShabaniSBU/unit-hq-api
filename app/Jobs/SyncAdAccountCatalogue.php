<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdPlatformAccount;
use App\Support\Advertising\AdCatalogueSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Backfill after an account is connected. Campaigns first, then 90 days of spend.
 */
class SyncAdAccountCatalogue implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('ads');
    }

    public function handle(AdCatalogueSync $sync): void
    {
        $account = AdPlatformAccount::query()->eligible()->find($this->accountId);

        if ($account === null) {
            return;
        }

        $sync->syncCampaigns($account);
        $account->refresh();
        $sync->syncSpend($account, 90);
    }
}
