<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Jobs\MaterialiseCampaignRecipients;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Support\Marketing\CampaignLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueCampaignSends extends Command
{
    protected $signature = 'campaigns:dispatch-due';

    protected $description = 'Queue materialisation for campaign sends that are due';

    public function handle(CampaignLifecycle $lifecycle): int
    {
        $sends = CampaignSend::query()
            ->where('status', CampaignSendStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereHas('campaign', function ($query): void {
                $query->whereIn('status', [
                    CampaignStatus::Scheduled->value,
                    CampaignStatus::Sending->value,
                ]);
            })
            ->orderBy('id')
            ->get();

        $queued = 0;

        foreach ($sends as $send) {
            $queued += DB::transaction(function () use ($send, $lifecycle): int {
                $updated = CampaignSend::query()
                    ->whereKey($send->id)
                    ->where('status', CampaignSendStatus::Scheduled)
                    ->update([
                        'status' => CampaignSendStatus::Materialising->value,
                        'updated_at' => now(),
                    ]);
                if ($updated === 0) {
                    return 0;
                }

                $campaign = Campaign::query()->lockForUpdate()->find($send->campaign_id);
                if ($campaign === null || ! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending], true)) {
                    CampaignSend::query()
                        ->whereKey($send->id)
                        ->where('status', CampaignSendStatus::Materialising)
                        ->update([
                            'status' => CampaignSendStatus::Scheduled->value,
                            'updated_at' => now(),
                        ]);

                    return 0;
                }

                if ($campaign->status === CampaignStatus::Scheduled) {
                    $lifecycle->markSending($campaign);
                }

                $sendId = $send->id;
                DB::afterCommit(static function () use ($sendId): void {
                    MaterialiseCampaignRecipients::dispatch($sendId);
                });

                return 1;
            });
        }

        $this->info("Queued {$queued} campaign send(s) for materialisation.");

        return self::SUCCESS;
    }
}
