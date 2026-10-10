<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Marketing\CampaignBatchSender;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class SendCampaignBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /**
     * Rate-limit releases count as attempts. A batch waiting on the account
     * limiter must not land in failed_jobs, so the cap is time, not a count.
     *
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public readonly int $campaignSendId,
        public readonly array $recipientIds,
        public readonly int $accountId,
        public readonly int $rateLimitPerMinute,
    ) {
        $this->onQueue('campaigns');
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addDay();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('campaign-account')];
    }

    public function handle(CampaignBatchSender $sender): void
    {
        $sender->send($this->campaignSendId, $this->recipientIds, $this->accountId, $this->rateLimitPerMinute);
    }
}
