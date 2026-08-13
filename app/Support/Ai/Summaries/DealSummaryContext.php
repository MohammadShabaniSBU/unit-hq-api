<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\Note;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\Unit;
use App\Support\Ai\Summaries\Concerns\BuildsSummaryContext;
use App\Support\Auth\Permission;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use App\Support\Time\SiteClock;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class DealSummaryContext implements SummaryContext
{
    use BuildsSummaryContext;

    public function __construct(
        private readonly Deal $deal,
        Employee $viewer,
        array $caps,
    ) {
        $this->viewer = $viewer;
        $this->caps = [
            'interactions' => (int) ($caps['interactions'] ?? 40),
            'notes' => (int) ($caps['notes'] ?? 20),
            'body_chars' => (int) ($caps['body_chars'] ?? 800),
        ];
    }

    public function subjectLabel(): string
    {
        $classLabel = $this->deal->desiredUnitClass?->label;

        return $classLabel !== null && $classLabel !== ''
            ? $classLabel
            : 'Deal #'.$this->deal->id;
    }

    public function build(): array
    {
        $this->deal->loadMissing(['desiredUnitClass', 'site', 'contact']);

        $offers = Offer::query()
            ->visibleTo($this->viewer, Permission::OfferManage)
            ->where('deal_id', $this->deal->id)
            ->with(['options' => fn ($q) => $q->orderBy('display_order')])
            ->orderByDesc('id')
            ->get();

        $reservations = Reservation::query()
            ->visibleTo($this->viewer, Permission::ReservationManage)
            ->where('deal_id', $this->deal->id)
            ->with(['unit.unitClass', 'unit.site'])
            ->orderByDesc('id')
            ->get();

        $contracts = Contract::query()
            ->visibleTo($this->viewer, Permission::ContractView)
            ->where('deal_id', $this->deal->id)
            ->with([
                'unitItem.price',
                'unitItem.item' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([Unit::class => ['unitClass', 'site']]);
                },
            ])
            ->orderByDesc('id')
            ->get();

        $interactions = Interaction::query()
            ->where('deal_id', $this->deal->id)
            ->latest('occurred_at')
            ->limit($this->caps['interactions'])
            ->get();

        $notes = Note::query()
            ->where('notable_type', $this->deal->getMorphClass())
            ->where('notable_id', $this->deal->id)
            ->latest('id')
            ->limit($this->caps['notes'])
            ->get();

        $tasks = Task::query()
            ->visibleTo($this->viewer, Permission::DealManage)
            ->where('taskable_type', $this->deal->getMorphClass())
            ->where('taskable_id', $this->deal->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderBy('due_at')
            ->get();

        $status = $this->deal->status instanceof DealStatus
            ? $this->deal->status->value
            : (string) $this->deal->status;

        $expectedMoveIn = null;
        if ($this->deal->expected_move_in !== null) {
            $expectedMoveIn = $this->deal->site !== null
                ? SiteClock::dateAt($this->deal->site, $this->deal->expected_move_in)->toDateString()
                : $this->deal->expected_move_in->toDateString();
        }

        $selectedOptions = [];
        foreach ($offers as $offer) {
            foreach ($offer->options as $option) {
                if ($option->selected_at !== null) {
                    $selectedOptions[] = [
                        'offer_id' => $offer->id,
                        'option_id' => $option->id,
                        'label' => $option->label,
                        'selected_at' => $option->selected_at->toIso8601String(),
                    ];
                }
            }
        }

        $enrolment = PlaybookEnrolmentSummary::activeForSubject(
            $this->deal->getMorphClass(),
            (int) $this->deal->id
        );

        return [
            'entity' => 'deal',
            'identity' => [
                'id' => $this->deal->id,
                'label' => $this->subjectLabel(),
                'status' => $status,
                'site' => $this->deal->site?->name,
                'contact_id' => $this->deal->contact_id,
                'contact_name' => $this->deal->contact !== null
                    ? trim($this->deal->contact->first_name.' '.$this->deal->contact->last_name)
                    : null,
            ],
            'expected_need' => [
                'expected_move_in' => $expectedMoveIn,
                'expected_stay_length' => $this->deal->expected_stay_length,
                'expected_stay_period' => $this->deal->expected_stay_period?->value
                    ?? (is_string($this->deal->expected_stay_period) ? $this->deal->expected_stay_period : null),
                'desired_size' => $this->deal->desired_size,
                'desired_unit_class' => $this->deal->desiredUnitClass?->label,
            ],
            'offers' => $offers->map(fn (Offer $offer): array => [
                'id' => $offer->id,
                'status' => (string) $offer->status,
                'expires_at' => $offer->expires_at?->toIso8601String(),
                'option_count' => $offer->options->count(),
            ])->values()->all(),
            'selected_options' => $selectedOptions,
            'reservations' => $reservations->map(fn (Reservation $reservation): array => [
                'id' => $reservation->id,
                'status' => (string) $reservation->status,
                'unit_number' => $reservation->unit?->unit_number,
                'unit_class' => $reservation->unit?->unitClass?->label,
            ])->values()->all(),
            'contracts' => $contracts->map(function (Contract $contract): array {
                $unit = $contract->unitItem?->item instanceof Unit ? $contract->unitItem->item : null;
                $amount = $contract->unitItem?->price?->amount;
                $currency = (string) ($contract->unitItem?->price?->currency ?? $contract->currency);

                return [
                    'id' => $contract->id,
                    'status' => $contract->status instanceof ContractStatus
                        ? $contract->status->value
                        : (string) $contract->status,
                    'unit_class' => $unit?->unitClass?->label,
                    'monthly_rate' => is_string($amount) && $currency !== ''
                        ? $this->money($amount, $currency)
                        : null,
                ];
            })->values()->all(),
            'playbook_enrolment' => $enrolment,
            'interactions' => $this->mapInteractions($interactions),
            'notes' => $this->mapNotes($notes),
            'open_tasks' => $this->mapOpenTasks($tasks),
        ];
    }

    public function counts(): array
    {
        $payload = $this->build();

        return [
            'interactions' => count($payload['interactions']),
            'notes' => count($payload['notes']),
            'offers' => count($payload['offers']),
            'reservations' => count($payload['reservations']),
            'contracts' => count($payload['contracts']),
            'open_tasks' => count($payload['open_tasks']),
        ];
    }
}
