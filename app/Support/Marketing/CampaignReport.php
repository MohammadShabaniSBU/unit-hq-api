<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConsentEvent;
use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Derived campaign stats. Nothing is stored on the campaign row.
 */
final class CampaignReport
{
    /**
     * @return array{opens_approximate: bool, sends: list<array<string, mixed>>, total: array<string, mixed>, engagement: array{anchor: string|null, hours: list<array{hour: int, opened: int, clicked: int}>}}
     */
    public function payload(Campaign $campaign): array
    {
        if ($campaign->status === CampaignStatus::Sending) {
            /** @var array{opens_approximate: bool, sends: list<array<string, mixed>>, total: array<string, mixed>, engagement: array{anchor: string|null, hours: list<array{hour: int, opened: int, clicked: int}>}} $cached */
            $cached = Cache::remember(
                'campaign-report:'.$campaign->id,
                60,
                fn (): array => $this->build($campaign),
            );

            return $cached;
        }

        return $this->build($campaign);
    }

    /**
     * @param  Collection<int, Campaign>  $campaigns
     */
    public function decorate(Collection $campaigns): void
    {
        if ($campaigns->isEmpty()) {
            return;
        }

        $campaigns->loadMissing('sends');
        $sendToCampaign = [];
        $buckets = [];

        foreach ($campaigns as $campaign) {
            $buckets[$campaign->id] = $this->emptyBucket();
            foreach ($campaign->sends as $send) {
                $sendToCampaign[$send->id] = $campaign->id;
            }
        }

        if ($sendToCampaign !== []) {
            $recipients = CampaignRecipient::query()
                ->whereIn('campaign_send_id', array_keys($sendToCampaign))
                ->get(['campaign_send_id', 'status', 'skip_reason', 'message_id']);

            $messageToCampaign = [];
            foreach ($recipients as $recipient) {
                $campaignId = $sendToCampaign[(int) $recipient->campaign_send_id] ?? null;
                if ($campaignId === null || ! isset($buckets[$campaignId])) {
                    continue;
                }
                $this->tally($buckets[$campaignId], $recipient);
                if ($recipient->message_id !== null) {
                    $messageToCampaign[(int) $recipient->message_id] = $campaignId;
                }
            }

            $this->applyDelivery($buckets, $messageToCampaign);
            $this->applyUnsubscribes($buckets, $messageToCampaign);
        }

        foreach ($campaigns as $campaign) {
            $this->applyListAttributes($campaign, $this->metrics($buckets[$campaign->id]));
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function applyListAttributes(Campaign $campaign, array $metrics): void
    {
        $campaign->setAttribute('list_counts', [
            'pending' => (int) $metrics['pending'],
            'claimed' => (int) $metrics['claimed'],
            'sent' => (int) $metrics['sent'],
            'skipped' => (int) $metrics['skipped'],
            'failed' => (int) $metrics['failed'],
        ]);
        $rates = $metrics['rates'] ?? [];
        $campaign->setAttribute('open_rate', is_array($rates) ? ($rates['opened'] ?? null) : null);
    }

    /**
     * @return array{opens_approximate: bool, sends: list<array<string, mixed>>, total: array<string, mixed>, engagement: array{anchor: string|null, hours: list<array{hour: int, opened: int, clicked: int}>}}
     */
    private function build(Campaign $campaign): array
    {
        $campaign->loadMissing('sends');
        $sends = [];

        foreach ($campaign->sends as $send) {
            $sends[$send->id] = [
                'id' => $send->id,
                'channel' => $send->channel->value,
                'bucket' => $this->emptyBucket(),
            ];
        }

        $messageToSend = [];
        $recipients = new Collection;
        if ($sends !== []) {
            $recipients = CampaignRecipient::query()
                ->whereIn('campaign_send_id', array_keys($sends))
                ->get(['campaign_send_id', 'status', 'skip_reason', 'message_id', 'processed_at']);

            foreach ($recipients as $recipient) {
                $sendId = (int) $recipient->campaign_send_id;
                if (! isset($sends[$sendId])) {
                    continue;
                }
                $this->tally($sends[$sendId]['bucket'], $recipient);
                if ($recipient->message_id !== null) {
                    $messageToSend[(int) $recipient->message_id] = $sendId;
                }
            }

            $this->applyDelivery($sends, $messageToSend, nested: true);
            $this->applyUnsubscribes($sends, $messageToSend, nested: true);
        }

        $payloads = [];
        $total = $this->emptyBucket();
        foreach ($sends as $send) {
            $this->addBucket($total, $send['bucket']);
            $payloads[] = [
                'id' => $send['id'],
                'channel' => $send['channel'],
                ...$this->metrics($send['bucket']),
            ];
        }

        return [
            'opens_approximate' => true,
            'sends' => $payloads,
            'total' => $this->metrics($total),
            'engagement' => $this->engagement($recipients, $messageToSend),
        ];
    }

    /**
     * Opens and clicks per hour since the earliest recipient was processed.
     *
     * @param  Collection<int, CampaignRecipient>  $recipients
     * @param  array<int, int>  $messageToSend
     * @return array{anchor: string|null, hours: list<array{hour: int, opened: int, clicked: int}>}
     */
    private function engagement(Collection $recipients, array $messageToSend): array
    {
        $anchor = null;
        foreach ($recipients as $recipient) {
            $processed = $recipient->processed_at;
            if (! $processed instanceof CarbonInterface) {
                continue;
            }
            if ($anchor === null || $processed->lt($anchor)) {
                $anchor = $processed->copy();
            }
        }

        /** @var array<int, array{opened: int, clicked: int}> $counts */
        $counts = [];
        $lastHour = 20;

        if ($anchor instanceof CarbonInterface && $messageToSend !== []) {
            $messages = Message::query()
                ->whereIn('id', array_keys($messageToSend))
                ->get(['id', 'delivery_events']);

            foreach ($messages as $message) {
                foreach ($message->delivery_events ?? [] as $event) {
                    if (! is_array($event)) {
                        continue;
                    }
                    $status = (string) ($event['status'] ?? '');
                    if ($status !== 'opened' && $status !== 'clicked') {
                        continue;
                    }
                    $occurredAt = $event['occurred_at'] ?? null;
                    if (! is_string($occurredAt) || $occurredAt === '') {
                        continue;
                    }
                    try {
                        $occurred = Carbon::parse($occurredAt);
                    } catch (\Throwable) {
                        continue;
                    }
                    $seconds = $occurred->getTimestamp() - $anchor->getTimestamp();
                    if ($seconds < 0) {
                        continue;
                    }
                    $hour = intdiv($seconds, 3600);
                    if ($hour > 72) {
                        continue;
                    }
                    $counts[$hour] ??= ['opened' => 0, 'clicked' => 0];
                    if ($status === 'opened') {
                        $counts[$hour]['opened']++;
                    } else {
                        $counts[$hour]['clicked']++;
                    }
                    if ($hour > $lastHour) {
                        $lastHour = $hour;
                    }
                }
            }
        }

        $hours = [];
        for ($hour = 0; $hour <= $lastHour; $hour++) {
            $hours[] = [
                'hour' => $hour,
                'opened' => $counts[$hour]['opened'] ?? 0,
                'clicked' => $counts[$hour]['clicked'] ?? 0,
            ];
        }

        return [
            'anchor' => $anchor?->toIso8601String(),
            'hours' => $hours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        $reasons = [];
        foreach (CampaignSkipReason::cases() as $reason) {
            $reasons[$reason->value] = 0;
        }

        return [
            'pending' => 0,
            'claimed' => 0,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'by_skip_reason' => $reasons,
            'delivered' => 0,
            'bounced' => 0,
            'opened' => 0,
            'clicked' => 0,
            'complained' => 0,
            'unsubscribed' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function tally(array &$bucket, CampaignRecipient $recipient): void
    {
        $status = $recipient->status instanceof CampaignRecipientStatus
            ? $recipient->status->value
            : (string) $recipient->status;

        if (! array_key_exists($status, $bucket)) {
            return;
        }

        $bucket[$status] = (int) $bucket[$status] + 1;

        if ($status !== CampaignRecipientStatus::Skipped->value || $recipient->skip_reason === null) {
            return;
        }

        $reason = $recipient->skip_reason instanceof CampaignSkipReason
            ? $recipient->skip_reason->value
            : (string) $recipient->skip_reason;
        $reasons = $bucket['by_skip_reason'];
        if (is_array($reasons) && array_key_exists($reason, $reasons)) {
            $reasons[$reason] = (int) $reasons[$reason] + 1;
            $bucket['by_skip_reason'] = $reasons;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $buckets
     * @param  array<int, int>  $messageToOwner
     */
    private function applyDelivery(array &$buckets, array $messageToOwner, bool $nested = false): void
    {
        if ($messageToOwner === []) {
            return;
        }

        $messages = Message::query()
            ->whereIn('id', array_keys($messageToOwner))
            ->get(['id', 'delivery_events']);

        foreach ($messages as $message) {
            $owner = $messageToOwner[(int) $message->id] ?? null;
            if ($owner === null) {
                continue;
            }

            $flags = $this->flags($message->delivery_events ?? []);
            $bucket = $nested ? ($buckets[$owner]['bucket'] ?? null) : ($buckets[$owner] ?? null);
            if (! is_array($bucket)) {
                continue;
            }

            foreach ($flags as $flag => $present) {
                if ($present) {
                    $bucket[$flag] = (int) ($bucket[$flag] ?? 0) + 1;
                }
            }

            if ($nested) {
                $buckets[$owner]['bucket'] = $bucket;
            } else {
                $buckets[$owner] = $bucket;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{delivered: bool, bounced: bool, opened: bool, clicked: bool, complained: bool}
     */
    private function flags(array $events): array
    {
        $flags = [
            'delivered' => false,
            'bounced' => false,
            'opened' => false,
            'clicked' => false,
            'complained' => false,
        ];

        foreach ($events as $event) {
            $status = (string) ($event['status'] ?? '');
            if ($status === 'delivered') {
                $flags['delivered'] = true;
            } elseif ($status === 'bounced') {
                $flags['bounced'] = true;
            } elseif ($status === 'opened') {
                $flags['opened'] = true;
            } elseif ($status === 'clicked') {
                $flags['clicked'] = true;
            } elseif ($status === 'spam') {
                $flags['complained'] = true;
            }
        }

        return $flags;
    }

    /**
     * @param  array<int, array<string, mixed>>  $buckets
     * @param  array<int, int>  $messageToOwner
     */
    private function applyUnsubscribes(array &$buckets, array $messageToOwner, bool $nested = false): void
    {
        if ($messageToOwner === []) {
            return;
        }

        $ids = array_map(strval(...), array_keys($messageToOwner));
        $expression = DB::getDriverName() === 'pgsql'
            ? "evidence->>'message_id'"
            : "CAST(json_extract(evidence, '$.message_id') AS TEXT)";

        $rows = ConsentEvent::query()
            ->where('action', ConsentAction::Withdrawn->value)
            ->whereIn(DB::raw($expression), $ids)
            ->get(['evidence']);

        $seen = [];
        foreach ($rows as $row) {
            $messageId = (int) ($row->evidence['message_id'] ?? 0);
            if ($messageId === 0 || isset($seen[$messageId]) || ! isset($messageToOwner[$messageId])) {
                continue;
            }
            $seen[$messageId] = true;
            $owner = $messageToOwner[$messageId];
            $bucket = $nested ? ($buckets[$owner]['bucket'] ?? null) : ($buckets[$owner] ?? null);
            if (! is_array($bucket)) {
                continue;
            }
            $bucket['unsubscribed'] = (int) $bucket['unsubscribed'] + 1;
            if ($nested) {
                $buckets[$owner]['bucket'] = $bucket;
            } else {
                $buckets[$owner] = $bucket;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $source
     */
    private function addBucket(array &$target, array $source): void
    {
        foreach (['pending', 'claimed', 'sent', 'skipped', 'failed', 'delivered', 'bounced', 'opened', 'clicked', 'complained', 'unsubscribed'] as $key) {
            $target[$key] = (int) $target[$key] + (int) $source[$key];
        }

        $reasons = $target['by_skip_reason'];
        $extra = $source['by_skip_reason'];
        if (is_array($reasons) && is_array($extra)) {
            foreach ($reasons as $reason => $count) {
                $reasons[$reason] = (int) $count + (int) ($extra[$reason] ?? 0);
            }
            $target['by_skip_reason'] = $reasons;
        }
    }

    /**
     * @param  array<string, mixed>  $bucket
     * @return array<string, mixed>
     */
    private function metrics(array $bucket): array
    {
        $pending = (int) $bucket['pending'];
        $claimed = (int) $bucket['claimed'];
        $sent = (int) $bucket['sent'];
        $skipped = (int) $bucket['skipped'];
        $failed = (int) $bucket['failed'];
        $delivered = (int) $bucket['delivered'];
        $opened = (int) $bucket['opened'];
        $clicked = (int) $bucket['clicked'];
        $unsubscribed = (int) $bucket['unsubscribed'];

        $rate = static function (int $count) use ($delivered): ?float {
            if ($delivered === 0) {
                return null;
            }

            return round($count / $delivered, 4);
        };

        return [
            'targeted' => $pending + $claimed + $sent + $skipped + $failed,
            'pending' => $pending,
            'claimed' => $claimed,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'by_skip_reason' => $bucket['by_skip_reason'],
            'delivered' => $delivered,
            'bounced' => (int) $bucket['bounced'],
            'opened' => $opened,
            'clicked' => $clicked,
            'complained' => (int) $bucket['complained'],
            'unsubscribed' => $unsubscribed,
            'rates' => [
                'opened' => $rate($opened),
                'clicked' => $rate($clicked),
                'unsubscribed' => $rate($unsubscribed),
            ],
        ];
    }
}
