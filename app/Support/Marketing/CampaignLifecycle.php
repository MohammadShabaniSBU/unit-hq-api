<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Employee;
use App\Models\Site;
use App\Models\SubscriptionList;
use App\Support\Auth\Permission;
use App\Support\RecordsActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every campaign status write. Locks the row, then records one activity event.
 */
final class CampaignLifecycle
{
    public function __construct(
        private readonly CampaignScheduleValidator $validator,
        private readonly CampaignBatchPlanner $batches,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Employee $actor, array $attributes): Campaign
    {
        $campaign = Campaign::query()->create([
            'type' => $attributes['type'],
            'name' => $attributes['name'],
            'status' => CampaignStatus::Draft,
            'subscription_list_id' => $attributes['subscription_list_id'] ?? null,
            'segment_id' => $attributes['segment_id'] ?? null,
            'site_ids' => array_values(array_map(intval(...), $attributes['site_ids'] ?? [])),
            'ignore_send_window' => (bool) ($attributes['ignore_send_window'] ?? false),
            'approval_required' => false,
            'created_by' => $actor->id,
        ]);

        $this->activity('campaign.created', $campaign, $actor, [
            'campaign_id' => $campaign->id,
            'type' => $campaign->type->value,
        ]);

        return $campaign->fresh(['sends']) ?? $campaign;
    }

    /**
     * @return list<string>
     */
    public function submit(Campaign $campaign, Employee $actor): array
    {
        return DB::transaction(function () use ($campaign, $actor): array {
            $locked = $this->lock($campaign);
            if ($locked->status !== CampaignStatus::Draft) {
                $this->deny($locked);
            }

            $prepared = $this->validator->prepare($locked, $actor);
            $this->applyPins($locked, $prepared->pins);

            $target = $prepared->approvalRequired
                ? CampaignStatus::PendingApproval
                : CampaignStatus::Scheduled;

            $attributes = [
                'status' => $target,
                'audience_snapshot' => $prepared->snapshot,
                'estimated_recipients' => $prepared->estimatedRecipients,
                'approval_required' => $prepared->approvalRequired,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'rejected_reason' => null,
            ];

            if ($locked->type === CampaignType::Newsletter) {
                $attributes['newsletter_issue_number'] = $this->assignNewsletterIssueNumber($locked);
            }

            $locked->update($attributes);

            if ($target === CampaignStatus::Scheduled) {
                $this->markSendsScheduled($locked);
            }

            $props = $this->versionProps($locked->fresh(['sends.templateVersion']) ?? $locked);
            $this->activity('campaign.submitted', $locked, $actor, $props);
            if ($target === CampaignStatus::Scheduled) {
                $this->activity('campaign.scheduled', $locked, $actor, $props);
            }

            return $prepared->warnings;
        });
    }

    public function approve(Campaign $campaign, Employee $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $locked = $this->lock($campaign);
            if ($locked->status !== CampaignStatus::PendingApproval) {
                $this->deny($locked);
            }

            if ((int) $locked->submitted_by === $actor->id && ! $this->isSoleApprover($actor->id)) {
                throw ValidationException::withMessages([
                    'approved_by' => [__('errors.campaigns.self_approval')],
                ]);
            }

            $pins = $this->validator->repin($locked);
            $this->applyPins($locked, $pins);

            $locked->update([
                'status' => CampaignStatus::Scheduled,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->markSendsScheduled($locked);

            $fresh = $locked->fresh(['sends.templateVersion']) ?? $locked;
            $props = $this->versionProps($fresh);
            $this->activity('campaign.approved', $fresh, $actor, $props);
            $this->activity('campaign.scheduled', $fresh, $actor, $props);

            return $fresh;
        });
    }

    public function reject(Campaign $campaign, Employee $actor, string $reason): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor, $reason): Campaign {
            $locked = $this->lock($campaign);
            if ($locked->status !== CampaignStatus::PendingApproval) {
                $this->deny($locked);
            }

            foreach ($locked->sends as $send) {
                if ($send->template_version_id === null && $send->status === CampaignSendStatus::Draft) {
                    continue;
                }
                $send->template_version_id = null;
                $send->status = CampaignSendStatus::Draft;
                $send->save();
            }

            $locked->update([
                'status' => CampaignStatus::Draft,
                'rejected_reason' => $reason,
            ]);

            $this->activity('campaign.rejected', $locked, $actor, [
                'campaign_id' => $locked->id,
                'status' => CampaignStatus::Draft->value,
            ]);

            return $locked->fresh(['sends']) ?? $locked;
        });
    }

    public function pause(Campaign $campaign, Employee $actor): Campaign
    {
        return $this->transition(
            $campaign,
            $actor,
            from: [CampaignStatus::Sending],
            to: CampaignStatus::Paused,
            event: 'campaign.paused',
        );
    }

    public function resume(Campaign $campaign, Employee $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $locked = $this->lock($campaign);
            if ($locked->status !== CampaignStatus::Paused) {
                $this->deny($locked);
            }

            $locked->update(['status' => CampaignStatus::Sending]);
            $this->activity('campaign.resumed', $locked, $actor, [
                'campaign_id' => $locked->id,
                'status' => CampaignStatus::Sending->value,
            ]);

            $campaignId = $locked->id;
            DB::afterCommit(function () use ($campaignId): void {
                $fresh = Campaign::query()->with('sends')->find($campaignId);
                if ($fresh === null || $fresh->status !== CampaignStatus::Sending) {
                    return;
                }

                foreach ($fresh->sends as $send) {
                    if ($send->status === CampaignSendStatus::Sending) {
                        $this->batches->queuePending($send);
                    }
                }
            });

            return $locked->fresh(['sends']) ?? $locked;
        });
    }

    public function cancel(Campaign $campaign, Employee $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $locked = $this->lock($campaign);
            if (! in_array($locked->status, [
                CampaignStatus::Scheduled,
                CampaignStatus::Sending,
                CampaignStatus::Paused,
            ], true)) {
                $this->deny($locked);
            }

            $locked->update([
                'status' => CampaignStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            foreach ($locked->sends as $send) {
                if ($send->status === CampaignSendStatus::Completed) {
                    continue;
                }
                $send->status = CampaignSendStatus::Cancelled;
                $send->save();
            }

            CampaignRecipient::query()
                ->whereIn('campaign_send_id', $locked->sends->pluck('id'))
                ->where('status', CampaignRecipientStatus::Pending)
                ->update([
                    'status' => CampaignRecipientStatus::Skipped->value,
                    'skip_reason' => CampaignSkipReason::Cancelled->value,
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->activity('campaign.cancelled', $locked, $actor, [
                'campaign_id' => $locked->id,
                'status' => CampaignStatus::Cancelled->value,
            ]);

            return $locked->fresh(['sends']) ?? $locked;
        });
    }

    public function markSending(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = $this->lock($campaign);
            if ($locked->status === CampaignStatus::Sending) {
                return $locked;
            }
            if ($locked->status !== CampaignStatus::Scheduled) {
                $this->deny($locked);
            }

            $locked->update(['status' => CampaignStatus::Sending]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function complete(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = $this->lock($campaign);
            if ($locked->status === CampaignStatus::Sent) {
                return $locked;
            }
            if ($locked->status !== CampaignStatus::Sending) {
                $this->deny($locked);
            }

            $locked->update(['status' => CampaignStatus::Sent]);

            $recipients = CampaignRecipient::query()
                ->whereIn('campaign_send_id', $locked->sends->pluck('id'));
            $counts = (clone $recipients)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all();
            $skipReasons = (clone $recipients)
                ->whereNotNull('skip_reason')
                ->selectRaw('skip_reason, count(*) as aggregate')
                ->groupBy('skip_reason')
                ->pluck('aggregate', 'skip_reason')
                ->all();

            $this->activity('campaign.completed', $locked, null, [
                'campaign_id' => $locked->id,
                'status' => CampaignStatus::Sent->value,
                'counts' => $counts,
                'skip_reasons' => $skipReasons,
            ]);
            $this->logAnnouncementSent($locked);

            return $locked->fresh(['sends']) ?? $locked;
        });
    }

    public function duplicate(Campaign $campaign, Employee $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $source = $this->lock($campaign);
            $copy = Campaign::query()->create([
                'type' => $source->type,
                'name' => $source->name,
                'status' => CampaignStatus::Draft,
                'subscription_list_id' => $source->subscription_list_id,
                'segment_id' => $source->segment_id,
                'site_ids' => $source->site_ids ?? [],
                'ignore_send_window' => $source->ignore_send_window,
                'approval_required' => false,
                'newsletter_issue_number' => null,
                'duplicated_from_id' => $source->id,
                'created_by' => $actor->id,
            ]);

            foreach ($source->sends as $send) {
                CampaignSend::query()->create([
                    'campaign_id' => $copy->id,
                    'channel' => $send->channel,
                    'template_family_id' => $send->template_family_id,
                    'template_version_id' => null,
                    'subject_override' => $send->subject_override,
                    'scheduled_at' => null,
                    'status' => CampaignSendStatus::Draft,
                ]);
            }

            $this->activity('campaign.created', $copy, $actor, [
                'campaign_id' => $copy->id,
                'duplicated_from_id' => $source->id,
                'type' => $copy->type->value,
            ]);

            return $copy->fresh(['sends']) ?? $copy;
        });
    }

    /**
     * @param  list<CampaignStatus>  $from
     */
    private function transition(
        Campaign $campaign,
        Employee $actor,
        array $from,
        CampaignStatus $to,
        string $event,
    ): Campaign {
        return DB::transaction(function () use ($campaign, $actor, $from, $to, $event): Campaign {
            $locked = $this->lock($campaign);
            if (! in_array($locked->status, $from, true)) {
                $this->deny($locked);
            }

            $locked->update(['status' => $to]);
            $this->activity($event, $locked, $actor, [
                'campaign_id' => $locked->id,
                'status' => $to->value,
            ]);

            return $locked->fresh(['sends']) ?? $locked;
        });
    }

    /**
     * Next issue number for this list. Caller holds the campaign row lock
     * inside a transaction. This locks the list row so two submits cannot
     * take the same integer. Cancelled issues keep their number and stay in
     * the max, so the next issue does not reuse it. A number already set
     * (reject, then submit again) is kept.
     */
    public function assignNewsletterIssueNumber(Campaign $campaign): int
    {
        if ($campaign->newsletter_issue_number !== null) {
            return (int) $campaign->newsletter_issue_number;
        }

        $listId = (int) $campaign->subscription_list_id;
        SubscriptionList::query()->whereKey($listId)->lockForUpdate()->first();

        $max = Campaign::query()
            ->where('subscription_list_id', $listId)
            ->where('type', CampaignType::Newsletter->value)
            ->max('newsletter_issue_number');

        return ((int) $max) + 1;
    }

    private function lock(Campaign $campaign): Campaign
    {
        $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
        $locked->load('sends');

        return $locked;
    }

    /**
     * @param  array<int, int>  $pins
     */
    private function applyPins(Campaign $campaign, array $pins): void
    {
        foreach ($campaign->sends as $send) {
            $versionId = $pins[$send->id] ?? null;
            if ($versionId === null || (int) $send->template_version_id === $versionId) {
                continue;
            }

            $send->template_version_id = $versionId;
            $send->save();
        }
    }

    private function markSendsScheduled(Campaign $campaign): void
    {
        foreach ($campaign->sends()->get() as $send) {
            $send->status = CampaignSendStatus::Scheduled;
            if ($send->scheduled_at === null) {
                $send->scheduled_at = now();
            }
            $send->save();
        }
    }

    private function isSoleApprover(int $employeeId): bool
    {
        $ids = Employee::query()
            ->whereNull('deactivated_at')
            ->whereIn('id', function ($query): void {
                $query->select('employee_roles.employee_id')
                    ->from('employee_roles')
                    ->join('role_permissions', 'role_permissions.role_id', '=', 'employee_roles.role_id')
                    ->where('role_permissions.permission', Permission::CampaignApprove->value);
            })
            ->pluck('id');

        return $ids->count() === 1 && (int) $ids->first() === $employeeId;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionProps(Campaign $campaign): array
    {
        $campaign->loadMissing('sends.templateVersion');
        $versions = [];
        foreach ($campaign->sends as $send) {
            $versions[] = [
                'campaign_send_id' => $send->id,
                'channel' => $send->channel->value,
                'template_version_id' => $send->template_version_id,
                'version_number' => $send->templateVersion?->version_number,
            ];
        }

        return [
            'campaign_id' => $campaign->id,
            'status' => $campaign->status->value,
            'estimated_recipients' => $campaign->estimated_recipients,
            'ignore_send_window' => (bool) $campaign->ignore_send_window,
            'versions' => $versions,
        ];
    }

    private function logAnnouncementSent(Campaign $campaign): void
    {
        if ($campaign->type !== CampaignType::Announcement) {
            return;
        }

        $campaign->loadMissing('sends');
        $versionIds = $campaign->sends
            ->pluck('template_version_id')
            ->filter(static fn (mixed $id): bool => $id !== null)
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        foreach (array_map(intval(...), $campaign->site_ids ?? []) as $siteId) {
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                continue;
            }

            RecordsActivity::log(LogChannel::Comms, 'campaign.announcement_sent', $site, [
                'campaign_id' => $campaign->id,
                'template_version_ids' => $versionIds,
                'recipient_count' => $this->announcementRecipientCount($campaign, $siteId),
                'ignore_send_window' => (bool) $campaign->ignore_send_window,
            ]);
        }
    }

    private function announcementRecipientCount(Campaign $campaign, int $siteId): int
    {
        $sendIds = $campaign->sends->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($sendIds === []) {
            return 0;
        }

        return CampaignRecipient::query()
            ->whereIn('campaign_send_id', $sendIds)
            ->whereIn('contact_id', function ($sub) use ($siteId): void {
                $sub->select('contracts.contact_id')
                    ->from('contracts')
                    ->join('unit_occupancies', 'unit_occupancies.contract_id', '=', 'contracts.id')
                    ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                    ->whereIn('contracts.status', [
                        ContractStatus::Active->value,
                        ContractStatus::NoticeGiven->value,
                    ])
                    ->whereNull('unit_occupancies.ended_on')
                    ->where('units.site_id', $siteId);
            })
            ->distinct()
            ->pluck('contact_id')
            ->unique()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function activity(string $event, Campaign $campaign, ?Employee $actor, array $properties): void
    {
        RecordsActivity::log(LogChannel::Comms, $event, $campaign, $properties, $actor);
    }

    private function deny(Campaign $campaign): never
    {
        throw ValidationException::withMessages([
            'status' => [__('errors.campaigns.illegal_transition', [
                'status' => $campaign->status->value,
            ])],
        ]);
    }
}
