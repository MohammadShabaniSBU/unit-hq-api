<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Enums\AttributeEntityType;
use App\Enums\AutomationRunStatus;
use App\Enums\CampaignType;
use App\Enums\ContractStatus;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Setting;
use App\Settings\MarketingSettings;
use App\Support\Auth\Permission;
use App\Support\Communications\Channel;
use App\Support\Communications\SendClass;
use App\Support\Communications\SuppressionScope;
use App\Support\Filtering\FilterBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Candidate contacts for one campaign send. Dispatch materialises this query.
 * Preview and the schedule estimate both read it, so the counts stay the same.
 */
final class CampaignAudienceQuery
{
    /**
     * @return array{
     *     count: int,
     *     by_skip_reason_estimate: array<string, int>,
     *     sample: list<array{id: int, first_name: string, last_name: string, email: string|null, status: string|null}>,
     *     channels: array<string, array{count: int, by_skip_reason_estimate: array<string, int>, sample: list<array{id: int, first_name: string, last_name: string, email: string|null, status: string|null}>}>,
     *     sms_unreachable?: int
     * }
     */
    public function preview(Campaign $campaign): array
    {
        $campaign->loadMissing('sends');
        $channels = [];

        foreach ($campaign->sends as $send) {
            $channel = $send->channel;
            $channels[$channel->value] = $this->summarise($campaign, $channel);
        }

        if ($channels === [] && $campaign->type === CampaignType::Announcement) {
            $summary = $this->summarise($campaign, Channel::Email);
            $summary['by_skip_reason_estimate'] = $this->emptySkips();

            return $this->withSmsUnreachable($campaign, [
                ...$summary,
                'channels' => [],
            ]);
        }

        $primary = $channels[Channel::Email->value] ?? reset($channels);
        if ($primary === false) {
            $primary = [
                'count' => 0,
                'by_skip_reason_estimate' => $this->emptySkips(),
                'sample' => [],
            ];
        }

        return $this->withSmsUnreachable($campaign, [
            ...$primary,
            'channels' => $channels,
        ]);
    }

    /**
     * Tenants who cannot receive an announcement by SMS because of an
     * all-scope suppression (hard bounce, complaint, or STOP).
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function withSmsUnreachable(Campaign $campaign, array $preview): array
    {
        if ($campaign->type !== CampaignType::Announcement) {
            return $preview;
        }

        $preview['sms_unreachable'] = $this->countSuppressed(
            $this->candidates($campaign, Channel::Sms),
            Channel::Sms,
            SendClass::Transactional,
        );

        return $preview;
    }

    public function estimate(Campaign $campaign): int
    {
        $preview = $this->preview($campaign);

        if ($preview['channels'] === []) {
            return $preview['count'];
        }

        return max(array_map(static fn (array $channel): int => $channel['count'], $preview['channels']));
    }

    /**
     * @return Builder<Contact>
     */
    public function candidates(Campaign $campaign, Channel $channel): Builder
    {
        $rules = $this->rules($campaign);
        $query = Contact::query();

        if ($campaign->type === CampaignType::Announcement) {
            if ($rules['site_ids'] === []) {
                return $query->whereRaw('1 = 0');
            }

            $siteIds = $rules['site_ids'];
            $query->whereIn('contacts.id', function ($sub) use ($siteIds): void {
                $sub->select('contracts.contact_id')
                    ->from('contracts')
                    ->join('unit_occupancies', 'unit_occupancies.contract_id', '=', 'contracts.id')
                    ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                    ->whereIn('contracts.status', [
                        ContractStatus::Active->value,
                        ContractStatus::NoticeGiven->value,
                    ])
                    ->whereNull('unit_occupancies.ended_on')
                    ->whereIn('units.site_id', $siteIds);
            });
        } else {
            if ($rules['list_id'] === null) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('contacts.id', ConsentState::grantedQuery($channel, $rules['list_id']));
            $this->constrainSites($query, $rules['site_ids']);
        }

        $filter = $rules['segment_filter'];
        if (is_array($filter) && ($filter['conditions'] ?? []) !== []) {
            FilterBuilder::for(AttributeEntityType::Contact)->apply($query, $filter);
        }

        $campaign->loadMissing('creator');
        $creator = $campaign->creator;
        if ($creator !== null && $creator->siteIdsFor(Permission::ContactView) !== null) {
            $query->visibleTo($creator, Permission::ContactView);
        }

        return $query;
    }

    /**
     * @return array{count: int, by_skip_reason_estimate: array<string, int>, sample: list<array{id: int, first_name: string, last_name: string, email: string|null, status: string|null}>}
     */
    private function summarise(Campaign $campaign, Channel $channel): array
    {
        $query = $this->candidates($campaign, $channel);
        $rules = $this->rules($campaign);
        $sendClass = $campaign->type === CampaignType::Announcement
            ? SendClass::Transactional
            : SendClass::Marketing;

        $sample = (clone $query)
            ->orderBy('contacts.id')
            ->limit(20)
            ->get(['contacts.id', 'contacts.first_name', 'contacts.last_name', 'contacts.email', 'contacts.status']);

        return [
            'count' => (clone $query)->count(),
            'by_skip_reason_estimate' => [
                'no_address' => $this->countMissingAddress(clone $query, $channel),
                'excluded_delinquent' => $rules['delinquent']
                    ? $this->countDelinquent(clone $query)
                    : 0,
                'excluded_playbook' => $rules['playbook_kinds'] === []
                    ? 0
                    : $this->countPlaybook(clone $query, $rules['playbook_kinds']),
                'frequency_capped' => CampaignSendGate::blocksOnFrequencyCap($campaign, $rules['newsletter_bypasses_cap'])
                    ? $this->countCapped(clone $query, $rules['cap_count'], $rules['cap_days'])
                    : 0,
                'suppressed' => $this->countSuppressed(clone $query, $channel, $sendClass),
            ],
            'sample' => $sample->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'status' => $contact->status?->value,
            ])->all(),
        ];
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function countMissingAddress(Builder $query, Channel $channel): int
    {
        if ($channel === Channel::Email) {
            return $query->where(function (Builder $inner): void {
                $inner->where(function (Builder $email): void {
                    $email->whereNull('contacts.email')->orWhere('contacts.email', '');
                })->whereNotExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('contact_channels')
                        ->whereColumn('contact_channels.contact_id', 'contacts.id')
                        ->where('contact_channels.type', Channel::Email->value)
                        ->where('contact_channels.value', '!=', '');
                });
            })->count();
        }

        return $query->whereNotExists(function ($sub) use ($channel): void {
            $sub->selectRaw('1')
                ->from('contact_channels')
                ->whereColumn('contact_channels.contact_id', 'contacts.id')
                ->whereIn('contact_channels.type', [$channel->value, 'phone'])
                ->where('contact_channels.value', '!=', '');
        })->count();
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function countDelinquent(Builder $query): int
    {
        return $query->whereExists(function ($sub): void {
            $sub->selectRaw('1')
                ->from('delinquencies')
                ->join('contracts', 'contracts.id', '=', 'delinquencies.contract_id')
                ->whereColumn('contracts.contact_id', 'contacts.id')
                ->whereNull('delinquencies.cured_on');
        })->count();
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  list<string>  $kinds
     */
    private function countPlaybook(Builder $query, array $kinds): int
    {
        $open = [
            AutomationRunStatus::Pending->value,
            AutomationRunStatus::Running->value,
            AutomationRunStatus::Waiting->value,
        ];

        return $query->whereExists(function ($sub) use ($kinds, $open): void {
            $sub->selectRaw('1')
                ->from('automation_runs')
                ->join('automations', 'automations.id', '=', 'automation_runs.automation_id')
                ->join('playbooks', 'playbooks.id', '=', 'automations.playbook_id')
                ->whereIn('playbooks.kind', $kinds)
                ->whereIn('automation_runs.status', $open)
                ->where(function ($subject): void {
                    $subject->where(function ($contact): void {
                        $contact->where('automation_runs.subject_type', 'contact')
                            ->whereColumn('automation_runs.subject_id', 'contacts.id');
                    })->orWhereExists(function ($deal): void {
                        $deal->selectRaw('1')
                            ->from('deals')
                            ->whereColumn('deals.contact_id', 'contacts.id')
                            ->where('automation_runs.subject_type', 'deal')
                            ->whereColumn('deals.id', 'automation_runs.subject_id');
                    })->orWhereExists(function ($contract): void {
                        $contract->selectRaw('1')
                            ->from('contracts')
                            ->whereColumn('contracts.contact_id', 'contacts.id')
                            ->where('automation_runs.subject_type', 'contract')
                            ->whereColumn('contracts.id', 'automation_runs.subject_id');
                    });
                });
        })->count();
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function countCapped(Builder $query, int $capCount, int $capDays): int
    {
        if ($capCount <= 0 || $capDays <= 0) {
            return 0;
        }

        $since = now()->subDays($capDays);

        return $query->whereIn('contacts.id', function ($sub) use ($since, $capCount): void {
            $sub->select('campaign_recipients.contact_id')
                ->from('campaign_recipients')
                ->join('campaign_sends', 'campaign_sends.id', '=', 'campaign_recipients.campaign_send_id')
                ->join('campaigns', 'campaigns.id', '=', 'campaign_sends.campaign_id')
                ->where('campaign_recipients.status', 'sent')
                ->where('campaign_recipients.processed_at', '>=', $since)
                ->where('campaigns.type', '!=', CampaignType::Announcement->value)
                ->groupBy('campaign_recipients.contact_id')
                ->havingRaw('COUNT(*) >= ?', [$capCount]);
        })->count();
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function countSuppressed(Builder $query, Channel $channel, SendClass $class): int
    {
        $scopes = [SuppressionScope::All->value];
        if ($class === SendClass::Marketing) {
            $scopes[] = SuppressionScope::Marketing->value;
        }

        return $query->whereExists(function ($sub) use ($channel, $scopes): void {
            $sub->selectRaw('1')
                ->from('channel_suppressions')
                ->where('channel_suppressions.channel', $channel->value)
                ->whereNull('channel_suppressions.lifted_at')
                ->whereIn('channel_suppressions.scope', $scopes)
                ->where(function ($address) use ($channel): void {
                    $types = $channel === Channel::Email
                        ? [Channel::Email->value]
                        : [$channel->value, 'phone'];
                    $address->whereExists(function ($row) use ($types): void {
                        $row->selectRaw('1')
                            ->from('contact_channels')
                            ->whereColumn('contact_channels.contact_id', 'contacts.id')
                            ->whereIn('contact_channels.type', $types)
                            ->whereRaw('channel_suppressions.address = lower(trim(contact_channels.value))');
                    });
                    if ($channel === Channel::Email) {
                        $address->orWhereRaw('channel_suppressions.address = lower(trim(contacts.email))');
                    }
                });
        })->count();
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  list<int>  $siteIds
     */
    private function constrainSites(Builder $query, array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($siteIds): void {
            $outer->whereIn('contacts.id', function ($sub) use ($siteIds): void {
                $sub->select('contact_id')->from('contact_sites')->whereIn('site_id', $siteIds);
            })->orWhereIn('contacts.id', function ($sub) use ($siteIds): void {
                $sub->select('contact_id')->from('deals')->whereIn('site_id', $siteIds);
            })->orWhereIn('contacts.id', function ($sub) use ($siteIds): void {
                $sub->select('contracts.contact_id')
                    ->from('contracts')
                    ->join('unit_occupancies', 'unit_occupancies.contract_id', '=', 'contracts.id')
                    ->join('units', 'units.id', '=', 'unit_occupancies.unit_id')
                    ->whereIn('units.site_id', $siteIds);
            });
        });
    }

    /**
     * Frozen audience rules. Dispatch gates read this, not the live settings.
     *
     * @return array{
     *     list_id: int|null,
     *     site_ids: list<int>,
     *     segment_filter: array<string, mixed>|null,
     *     delinquent: bool,
     *     playbook_kinds: list<string>,
     *     cap_count: int,
     *     cap_days: int,
     *     newsletter_bypasses_cap: bool
     * }
     */
    public function rulesFor(Campaign $campaign): array
    {
        return $this->rules($campaign);
    }

    /**
     * @return array{
     *     list_id: int|null,
     *     site_ids: list<int>,
     *     segment_filter: array<string, mixed>|null,
     *     delinquent: bool,
     *     playbook_kinds: list<string>,
     *     cap_count: int,
     *     cap_days: int,
     *     newsletter_bypasses_cap: bool
     * }
     */
    private function rules(Campaign $campaign): array
    {
        $settings = Setting::marketing();
        $snapshot = $campaign->audience_snapshot;

        if (is_array($snapshot) && isset($snapshot['exclusions'], $snapshot['frequency_cap'])) {
            $kinds = $snapshot['exclusions']['playbook_kinds'] ?? [];

            return [
                'list_id' => isset($snapshot['list_id']) ? (int) $snapshot['list_id'] : $campaign->subscription_list_id,
                'site_ids' => array_values(array_map(intval(...), $snapshot['site_ids'] ?? [])),
                'segment_filter' => is_array($snapshot['segment_filter'] ?? null) ? $snapshot['segment_filter'] : null,
                'delinquent' => (bool) ($snapshot['exclusions']['delinquent'] ?? false),
                'playbook_kinds' => array_values(array_map(strval(...), is_array($kinds) ? $kinds : [])),
                'cap_count' => (int) ($snapshot['frequency_cap']['count'] ?? 0),
                'cap_days' => (int) ($snapshot['frequency_cap']['days'] ?? 0),
                'newsletter_bypasses_cap' => array_key_exists('newsletter_bypasses_cap', $snapshot['frequency_cap'])
                    ? (bool) $snapshot['frequency_cap']['newsletter_bypasses_cap']
                    : true,
            ];
        }

        $segmentFilter = null;
        if ($campaign->segment_id !== null) {
            $campaign->loadMissing('segment');
            $filter = $campaign->segment?->filter;
            $segmentFilter = is_array($filter) ? $filter : null;
        }

        return [
            'list_id' => $campaign->subscription_list_id,
            'site_ids' => array_values(array_map(intval(...), $campaign->site_ids ?? [])),
            'segment_filter' => $segmentFilter,
            'delinquent' => $settings->excludeDelinquent,
            'playbook_kinds' => array_values($settings->excludeActivePlaybookKinds),
            'cap_count' => $settings->frequencyCapCount,
            'cap_days' => $settings->frequencyCapDays,
            'newsletter_bypasses_cap' => $settings->newsletterBypassesCap,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptySkips(): array
    {
        return [
            'no_address' => 0,
            'excluded_delinquent' => 0,
            'excluded_playbook' => 0,
            'frequency_capped' => 0,
            'suppressed' => 0,
        ];
    }

    public function snapshot(Campaign $campaign, MarketingSettings $settings): array
    {
        $segmentFilter = null;
        if ($campaign->segment_id !== null) {
            $campaign->loadMissing('segment');
            $filter = $campaign->segment?->filter;
            $segmentFilter = is_array($filter) ? $filter : null;
        }

        return [
            'list_id' => $campaign->subscription_list_id,
            'segment_filter' => $segmentFilter,
            'site_ids' => array_values(array_map(intval(...), $campaign->site_ids ?? [])),
            'exclusions' => [
                'delinquent' => $settings->excludeDelinquent,
                'playbook_kinds' => array_values($settings->excludeActivePlaybookKinds),
            ],
            'frequency_cap' => [
                'count' => $settings->frequencyCapCount,
                'days' => $settings->frequencyCapDays,
                'newsletter_bypasses_cap' => $settings->newsletterBypassesCap,
            ],
        ];
    }
}
