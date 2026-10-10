<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use Illuminate\Support\Facades\DB;

/**
 * Releases claims a dead worker left behind, then closes sends that have
 * nothing left to deliver.
 */
final class CampaignSendCompletion
{
    public const STALE_CLAIM_MINUTES = 15;

    public function __construct(
        private readonly CampaignBatchPlanner $batches,
        private readonly CampaignLifecycle $lifecycle,
    ) {}

    public function sweep(): void
    {
        $cutoff = now()->subMinutes(self::STALE_CLAIM_MINUTES);
        $stale = CampaignRecipient::query()
            ->where('status', CampaignRecipientStatus::Claimed)
            ->where('claimed_at', '<', $cutoff)
            ->get(['id', 'campaign_send_id']);

        foreach ($stale->groupBy('campaign_send_id') as $sendId => $rows) {
            $send = CampaignSend::query()->with('campaign')->find((int) $sendId);
            if ($send === null || $send->campaign === null) {
                continue;
            }

            $ids = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $status = $send->campaign->status;

            if ($status === CampaignStatus::Cancelled) {
                $this->skip($ids, CampaignSkipReason::Cancelled);

                continue;
            }

            DB::table('campaign_recipients')
                ->whereIn('id', $ids)
                ->where('status', CampaignRecipientStatus::Claimed->value)
                ->update([
                    'status' => CampaignRecipientStatus::Pending->value,
                    'claimed_at' => null,
                    'updated_at' => now(),
                ]);

            if ($status === CampaignStatus::Sending && $send->status === CampaignSendStatus::Sending) {
                $this->batches->queuePending($send, $ids);
            }
        }

        CampaignSend::query()
            ->where('status', CampaignSendStatus::Sending)
            ->orderBy('id')
            ->each(function (CampaignSend $send): void {
                $this->completeSendIfIdle($send);
            });
    }

    public function completeSendIfIdle(CampaignSend $send): void
    {
        $send->refresh();
        if ($send->status !== CampaignSendStatus::Sending) {
            return;
        }

        $open = CampaignRecipient::query()
            ->where('campaign_send_id', $send->id)
            ->whereIn('status', [CampaignRecipientStatus::Pending, CampaignRecipientStatus::Claimed])
            ->exists();
        if ($open) {
            return;
        }

        $send->status = CampaignSendStatus::Completed;
        $send->completed_at = now();
        $send->save();

        $campaign = $send->campaign()->first();
        if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $campaign->load('sends');
        $allDone = $campaign->sends->every(
            fn (CampaignSend $row): bool => $row->status === CampaignSendStatus::Completed,
        );
        if ($allDone) {
            $this->lifecycle->complete($campaign);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function skip(array $ids, CampaignSkipReason $reason): void
    {
        CampaignRecipient::query()
            ->whereIn('id', $ids)
            ->where('status', CampaignRecipientStatus::Claimed)
            ->update([
                'status' => CampaignRecipientStatus::Skipped->value,
                'skip_reason' => $reason->value,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
