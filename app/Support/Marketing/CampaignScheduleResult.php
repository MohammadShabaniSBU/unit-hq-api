<?php

declare(strict_types=1);

namespace App\Support\Marketing;

/**
 * Result of a successful schedule check. The lifecycle applies the writes.
 */
final readonly class CampaignScheduleResult
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $warnings
     * @param  array<int, int>  $pins  send id => template version id
     */
    public function __construct(
        public array $snapshot,
        public int $estimatedRecipients,
        public bool $approvalRequired,
        public array $warnings,
        public array $pins,
    ) {}
}
