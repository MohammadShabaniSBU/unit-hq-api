<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CampaignSend;
use App\Support\Marketing\CampaignMaterialiser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MaterialiseCampaignRecipients implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $campaignSendId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(CampaignMaterialiser $materialiser): void
    {
        $send = CampaignSend::query()->find($this->campaignSendId);
        if ($send === null) {
            return;
        }

        $materialiser->materialise($send);
    }
}
