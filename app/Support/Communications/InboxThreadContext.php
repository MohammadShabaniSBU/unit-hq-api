<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\AutopayAttemptStatus;
use App\Enums\ContactChannelType;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\DelinquencyStepAction;
use App\Enums\HoldType;
use App\Models\AutopayAttempt;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\DelinquencyStep;
use App\Models\CallWrapup;
use App\Models\Interaction;
use App\Models\MessageThread;
use App\Models\Playbook;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Delinquency\Overlock;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * S11-03 inbox context aggregate: contact + tenancy + pipeline + recent activity,
 * computed live over the standing balance/delinquency/enrolment helpers — nothing
 * new is stored, and panel figures must never drift from their source pages.
 */
final class InboxThreadContext
{
    private const RECENT_LIMIT = 3;

    /** @return array<string, mixed> */
    public static function build(MessageThread $thread): array
    {
        $contact = $thread->contact;

        if ($contact === null) {
            return [
                'contact' => null,
                'tenancy' => ['active_contracts' => []],
                'pipeline' => ['open_deal' => null, 'lead_enrolment' => null],
                'recent' => [],
            ];
        }

        $contact->loadMissing('channels');

        return [
            'contact' => self::contactCard($contact),
            'tenancy' => ['active_contracts' => self::activeContracts($contact)],
            'pipeline' => self::pipeline($contact),
            'recent' => self::recent($contact),
        ];
    }

    /** @return array<string, mixed> */
    private static function contactCard(Contact $contact): array
    {
        $email = self::primaryChannelValue($contact, ContactChannelType::Email) ?? $contact->email;
        $phone = self::primaryChannelValue($contact, ContactChannelType::Phone);

        $pairs = [];
        foreach ($contact->channels as $channel) {
            $commsChannel = self::commsChannelFor($channel->type);
            if ($commsChannel === null) {
                continue;
            }
            $pairs[] = [$commsChannel, $channel->value];
        }

        $suppressedMap = $pairs !== [] ? SuppressionWriter::transactionalBlockedMap($pairs) : [];

        $channels = $contact->channels->map(function ($channel) use ($suppressedMap) {
            $commsChannel = self::commsChannelFor($channel->type);
            $suppressed = false;
            if ($commsChannel !== null) {
                $normalized = ContactChannelMatcher::normalize($commsChannel, $channel->value);
                $suppressed = $suppressedMap[$commsChannel->value.'|'.$normalized] ?? false;
            }

            return [
                'type' => $channel->type->value,
                'value' => $channel->value,
                'suppressed' => $suppressed,
            ];
        })->values()->all();

        return [
            'id' => $contact->id,
            'name' => trim($contact->first_name.' '.$contact->last_name),
            'status' => $contact->status?->value,
            'email' => $email,
            'phone' => $phone,
            'fiscal_complete' => $contact->fiscalComplete(),
            'channels' => $channels,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function activeContracts(Contact $contact): array
    {
        $contracts = $contact->contracts()
            ->whereIn('status', [
                ContractStatus::Pending->value,
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])
            ->with([
                'unitItem.price',
                'unitItem.item' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([Unit::class => ['site']]);
                },
                'delinquencies' => fn ($query) => $query->whereNull('cured_on'),
            ])
            ->orderByDesc('id')
            ->get();

        if ($contracts->isEmpty()) {
            return [];
        }

        /** @var Collection<int, AutopayAttempt> $latestAttempts */
        $latestAttempts = AutopayAttempt::query()
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('contract_id')
            ->map(fn (Collection $group) => $group->first());

        $caseIds = $contracts->flatMap(fn (Contract $c) => $c->delinquencies)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $liveOverlockCaseIds = [];
        /** @var \Illuminate\Support\Collection<int, DelinquencyStep> $latestSteps */
        $latestSteps = collect();
        if ($caseIds !== []) {
            $reasons = array_map(fn (int $id) => "delinquency:{$id}", $caseIds);

            $liveHolds = UnitHold::query()
                ->where('hold_type', HoldType::Overlock)
                ->whereNull('released_at')
                ->whereIn('reason', $reasons)
                ->pluck('reason');

            foreach ($liveHolds as $reason) {
                $id = Overlock::delinquencyIdFromReason(is_string($reason) ? $reason : null);
                if ($id !== null) {
                    $liveOverlockCaseIds[] = $id;
                }
            }

            // One query for every case's steps, latest-first — grouped in PHP to
            // avoid the eager-load "limit inside relation" pitfall (that limits
            // rows across all parents, not per parent).
            $latestSteps = DelinquencyStep::query()
                ->whereIn('delinquency_id', $caseIds)
                ->orderByDesc('id')
                ->get()
                ->groupBy('delinquency_id')
                ->map(fn (Collection $group) => $group->first());
        }

        return $contracts
            ->map(fn (Contract $contract) => self::contractBlock(
                $contract,
                $latestAttempts->get($contract->id),
                $liveOverlockCaseIds,
                $latestSteps,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $liveOverlockCaseIds
     * @param  \Illuminate\Support\Collection<int, DelinquencyStep>  $latestSteps
     * @return array<string, mixed>
     */
    private static function contractBlock(
        Contract $contract,
        ?AutopayAttempt $latestAttempt,
        array $liveOverlockCaseIds,
        \Illuminate\Support\Collection $latestSteps,
    ): array {
        $unit = $contract->unitItem?->item instanceof Unit ? $contract->unitItem->item : null;

        $autopay = 'off';
        $autopayAttemptId = null;
        if ($contract->autopay_enabled) {
            if ($latestAttempt !== null && $latestAttempt->status === AutopayAttemptStatus::Failed) {
                $autopay = 'failing';
                $autopayAttemptId = $latestAttempt->id;
            } else {
                $autopay = 'on';
            }
        }

        /** @var Delinquency|null $openCase */
        $openCase = $contract->delinquencies->first();
        $delinquency = null;
        if ($openCase !== null) {
            $delinquency = [
                'id' => $openCase->id,
                'days' => DelinquencyState::daysOverdue($contract),
                'stage_label' => in_array($openCase->id, $liveOverlockCaseIds, true)
                    ? 'Overlocked'
                    : self::stageLabelFromSteps($latestSteps->get($openCase->id)),
            ];
        }

        return [
            'id' => $contract->id,
            'unit_number' => $unit?->unit_number,
            'site_name' => $unit?->site?->name,
            'monthly_display' => [
                'amount' => $contract->unitItem?->price?->amount,
                'currency' => $contract->unitItem?->price?->currency ?? $contract->currency,
            ],
            'balance' => [
                'owed' => $contract->balanceOwed(),
                'overdue' => $contract->overdueAmount(),
                'currency' => $contract->currency,
            ],
            'autopay' => $autopay,
            'autopay_attempt_id' => $autopayAttemptId,
            'delinquency' => $delinquency,
        ];
    }

    private static function stageLabelFromSteps(?DelinquencyStep $latestStep): string
    {
        if ($latestStep === null) {
            return 'Delinquent';
        }

        $action = $latestStep->action instanceof DelinquencyStepAction
            ? $latestStep->action->value
            : (string) $latestStep->action;

        return self::humanize($action);
    }

    /** @return array{open_deal: array<string, mixed>|null, lead_enrolment: array<string, mixed>|null} */
    private static function pipeline(Contact $contact): array
    {
        $deal = $contact->deals()
            ->whereIn('status', DealStatus::activePursuitValues())
            ->with('site')
            ->orderByDesc('id')
            ->first();

        if ($deal === null) {
            return ['open_deal' => null, 'lead_enrolment' => null];
        }

        $status = $deal->status instanceof DealStatus ? $deal->status->value : (string) $deal->status;
        $siteName = $deal->site?->name;

        $openDeal = [
            'id' => $deal->id,
            'title' => trim(($siteName !== null ? $siteName.' · ' : '').self::humanize($status)),
            'stage' => $status,
            'move_in' => $deal->expected_move_in?->toDateString(),
        ];

        $enrolment = PlaybookEnrolmentSummary::activeForSubject('deal', (int) $deal->id);
        $leadEnrolment = null;
        if ($enrolment !== null) {
            $playbook = Playbook::query()->find($enrolment['playbook_id']);
            $leadEnrolment = [
                'playbook_id' => $enrolment['playbook_id'],
                'playbook' => $playbook?->name,
                'step_x_of_y' => "{$enrolment['step_index']} of {$enrolment['step_total']}",
                'next_at' => $enrolment['waiting_until'],
            ];
        }

        return ['open_deal' => $openDeal, 'lead_enrolment' => $leadEnrolment];
    }

    /**
     * @return list<array{type: string, at: string|null, summary: string|null, disposition: string|null}>
     */
    private static function recent(Contact $contact): array
    {
        $interactions = $contact->interactions()
            ->orderByDesc('occurred_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $callMessageIds = $interactions
            ->filter(fn (Interaction $i): bool => $i->channel === 'call' && $i->message_id !== null)
            ->pluck('message_id')
            ->unique()
            ->values()
            ->all();

        $dispositions = $callMessageIds === []
            ? collect()
            : CallWrapup::query()
                ->whereIn('message_id', $callMessageIds)
                ->pluck('disposition', 'message_id');

        return $interactions
            ->map(function (Interaction $interaction) use ($dispositions): array {
                $disposition = null;
                if ($interaction->channel === 'call' && $interaction->message_id !== null) {
                    $raw = $dispositions->get($interaction->message_id);
                    $disposition = is_string($raw) && $raw !== '' ? $raw : null;
                }

                $summary = $interaction->summary
                    ?? ($interaction->content !== null ? Str::limit($interaction->content, 140) : null);
                if ($disposition !== null) {
                    $label = self::humanize($disposition);
                    $summary = $summary !== null && $summary !== ''
                        ? $label.' · '.$summary
                        : $label;
                }

                return [
                    'type' => $interaction->channel,
                    'at' => $interaction->occurred_at?->toIso8601String(),
                    'summary' => $summary,
                    'disposition' => $disposition,
                ];
            })
            ->values()
            ->all();
    }

    private static function primaryChannelValue(Contact $contact, ContactChannelType $type): ?string
    {
        $primary = $contact->channels->first(fn ($channel) => $channel->type === $type && $channel->is_primary);
        if ($primary !== null) {
            return (string) $primary->value;
        }

        $any = $contact->channels->first(fn ($channel) => $channel->type === $type);

        return $any !== null ? (string) $any->value : null;
    }

    /**
     * Phone-type channel values have no direct Channel (communications) counterpart
     * here — they aren't independently suppressible outside an SMS/call context.
     */
    private static function commsChannelFor(ContactChannelType $type): ?Channel
    {
        return match ($type) {
            ContactChannelType::Email => Channel::Email,
            ContactChannelType::Sms => Channel::Sms,
            ContactChannelType::Whatsapp => Channel::Whatsapp,
            ContactChannelType::Phone => null,
        };
    }

    private static function humanize(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
