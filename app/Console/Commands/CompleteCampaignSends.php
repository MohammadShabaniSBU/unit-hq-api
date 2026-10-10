<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Marketing\CampaignSendCompletion;
use Illuminate\Console\Command;

class CompleteCampaignSends extends Command
{
    protected $signature = 'campaigns:complete-sends';

    protected $description = 'Release stale campaign claims and complete idle sends';

    public function handle(CampaignSendCompletion $completion): int
    {
        $completion->sweep();
        $this->info('Campaign send completion sweep finished.');

        return self::SUCCESS;
    }
}
