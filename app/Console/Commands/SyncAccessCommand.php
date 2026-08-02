<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Access\AccessReconciler;
use Illuminate\Console\Command;

/**
 * Declarative access sync. Full (unscoped) runs include provider drift checks.
 */
class SyncAccessCommand extends Command
{
    protected $signature = 'access:sync
                            {--site= : Limit to a site id}
                            {--contract= : Limit to a contract id}
                            {--dry-run : Print the diff sets without writing}';

    protected $description = 'Reconcile desired access grants with the provider cache';

    public function handle(AccessReconciler $reconciler): int
    {
        $siteOption = $this->option('site');
        $contractOption = $this->option('contract');
        $dryRun = (bool) $this->option('dry-run');

        $siteId = $siteOption !== null && $siteOption !== ''
            ? (int) $siteOption
            : null;
        $contractId = $contractOption !== null && $contractOption !== ''
            ? (int) $contractOption
            : null;

        $withDrift = $siteId === null && $contractId === null && ! $dryRun;

        $summary = $reconciler->run(
            siteId: $siteId,
            contractId: $contractId,
            dryRun: $dryRun,
            withDrift: $withDrift,
        );

        if ($dryRun) {
            $this->info('Dry-run — no writes.');
            $this->line('to_grant: '.json_encode($summary['to_grant'], JSON_THROW_ON_ERROR));
            $this->line('to_revoke: '.json_encode($summary['to_revoke'], JSON_THROW_ON_ERROR));
            $this->line('stuck: '.json_encode($summary['stuck'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Access sync finished: granted=%d revoked=%d failed=%d retried=%d drift[unknown=%d missing=%d denied=%d]',
            $summary['granted'],
            $summary['revoked'],
            $summary['failed'],
            $summary['retried'],
            $summary['drift']['unknown'],
            $summary['drift']['missing'],
            $summary['drift']['denied_but_granted'],
        ));

        return self::SUCCESS;
    }
}
