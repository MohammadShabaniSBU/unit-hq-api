<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Jobs\SendCampaignBatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Support\Communications\Channel;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\ProviderResolver;
use DateTimeInterface;

/**
 * Groups pending recipients by site and account, then queues batches of 100.
 * The rate limit is per account; the site split keeps one send window per batch.
 */
final class CampaignBatchPlanner
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly ProviderResolver $resolver,
    ) {}

    /**
     * @param  list<int>|null  $onlyIds
     */
    public function queuePending(CampaignSend $send, ?array $onlyIds = null, ?DateTimeInterface $delay = null): void
    {
        $send->loadMissing('campaign');
        $campaign = $send->campaign;
        if ($campaign === null) {
            return;
        }

        $query = CampaignRecipient::query()
            ->where('campaign_send_id', $send->id)
            ->where('status', CampaignRecipientStatus::Pending);
        if ($onlyIds !== null) {
            $query->whereIn('id', $onlyIds);
        }

        $rows = $query->get(['id', 'site_id']);
        if ($rows->isEmpty()) {
            return;
        }

        $class = CampaignSendGate::sendClass($campaign);
        $channel = $send->channel;

        foreach ($rows->groupBy(fn (CampaignRecipient $row): int => (int) ($row->site_id ?? 0)) as $siteKey => $group) {
            $ids = $group->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            $site = $siteKey === 0 ? null : Site::query()->find((int) $siteKey);
            if (! $site instanceof Site) {
                $this->skip($ids, CampaignSkipReason::ChannelNotConfigured);

                continue;
            }

            try {
                $resolved = $this->resolver->resolve($channel, $site, $class);
            } catch (ChannelNotConfigured) {
                $this->skip($ids, CampaignSkipReason::ChannelNotConfigured);

                continue;
            }

            $limit = $this->perMinute($resolved->account, $channel);
            foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
                $pending = SendCampaignBatch::dispatch($send->id, $chunk, (int) $resolved->account->id, $limit);
                if ($delay !== null) {
                    $pending->delay($delay);
                }
            }
        }
    }

    public function perMinute(CommunicationAccount $account, Channel $channel): int
    {
        $credentials = $account->credentials ?? [];
        $raw = $credentials['rate_limit_per_minute'] ?? null;
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return $channel === Channel::Email ? 600 : 60;
    }

    /**
     * @param  list<int>  $ids
     */
    private function skip(array $ids, CampaignSkipReason $reason): void
    {
        if ($ids === []) {
            return;
        }

        CampaignRecipient::query()
            ->whereIn('id', $ids)
            ->where('status', CampaignRecipientStatus::Pending)
            ->update([
                'status' => CampaignRecipientStatus::Skipped->value,
                'skip_reason' => $reason->value,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
