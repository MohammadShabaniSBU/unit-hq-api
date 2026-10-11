<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdPlatformAccount;
use App\Support\Advertising\AdCatalogueSync;
use Illuminate\Console\Command;

class SyncAdSpendCommand extends Command
{
    protected $signature = 'ads:sync-spend {--days=7}';

    protected $description = 'Sync trailing ad spend for each active account';

    public function handle(AdCatalogueSync $sync): int
    {
        $days = $sync->clampDays((int) $this->option('days'));

        AdPlatformAccount::query()->eligible()->orderBy('id')->each(
            function (AdPlatformAccount $account) use ($sync, $days): void {
                $sync->syncSpend($account, $days);
            },
        );

        return self::SUCCESS;
    }
}
