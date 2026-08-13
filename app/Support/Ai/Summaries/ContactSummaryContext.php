<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Delinquency;
use App\Models\Employee;
use App\Models\Interaction;
use App\Models\Note;
use App\Models\Task;
use App\Models\Unit;
use App\Support\Ai\Summaries\Concerns\BuildsSummaryContext;
use App\Support\Auth\Permission;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Time\SiteClock;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class ContactSummaryContext implements SummaryContext
{
    use BuildsSummaryContext;

    public function __construct(
        private readonly Contact $contact,
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
        return trim($this->contact->first_name.' '.$this->contact->last_name) ?: 'Contact #'.$this->contact->id;
    }

    public function build(): array
    {
        $deals = Deal::query()
            ->visibleTo($this->viewer, Permission::DealManage)
            ->where('contact_id', $this->contact->id)
            ->with('desiredUnitClass')
            ->orderByDesc('id')
            ->get();

        $contracts = Contract::query()
            ->visibleTo($this->viewer, Permission::ContractView)
            ->where('contact_id', $this->contact->id)
            ->whereIn('status', [
                ContractStatus::Pending->value,
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])
            ->with([
                'unitItem.price',
                'unitItem.item' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([Unit::class => ['unitClass', 'site']]);
                },
                'delinquencies' => fn ($q) => $q->whereNull('cured_on'),
            ])
            ->orderByDesc('id')
            ->get();

        $channelTypes = $this->contact->channels()
            ->get(['type'])
            ->pluck('type')
            ->map(fn ($type) => $type instanceof \BackedEnum ? $type->value : (string) $type)
            ->unique()
            ->values()
            ->all();

        $interactions = Interaction::query()
            ->where('contact_id', $this->contact->id)
            ->latest('occurred_at')
            ->limit($this->caps['interactions'])
            ->get();

        $notes = Note::query()
            ->where('notable_type', $this->contact->getMorphClass())
            ->where('notable_id', $this->contact->id)
            ->latest('id')
            ->limit($this->caps['notes'])
            ->get();

        $tasks = Task::query()
            ->visibleTo($this->viewer, Permission::ContactView)
            ->where('taskable_type', $this->contact->getMorphClass())
            ->where('taskable_id', $this->contact->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderBy('due_at')
            ->get();

        $openDeals = $deals->filter(function (Deal $deal): bool {
            $status = $deal->status instanceof DealStatus
                ? $deal->status
                : DealStatus::tryFrom((string) $deal->status);

            return $status !== null && ! $status->isTerminal();
        });

        $won = $deals->filter(fn (Deal $d) => ($d->status instanceof DealStatus ? $d->status : DealStatus::tryFrom((string) $d->status)) === DealStatus::ClosedWon)->count();
        $lost = $deals->filter(fn (Deal $d) => ($d->status instanceof DealStatus ? $d->status : DealStatus::tryFrom((string) $d->status)) === DealStatus::ClosedLost)->count();

        $balancesByCurrency = [];
        foreach ($contracts as $contract) {
            $currency = (string) $contract->currency;
            if ($currency === '') {
                continue;
            }
            $balancesByCurrency[$currency] = bcadd(
                $balancesByCurrency[$currency] ?? '0.00',
                $contract->balanceOwed(),
                2
            );
        }

        $balances = [];
        foreach ($balancesByCurrency as $currency => $amount) {
            $balances[] = $this->money($amount, $currency);
        }

        return [
            'entity' => 'contact',
            'identity' => [
                'id' => $this->contact->id,
                'name' => $this->subjectLabel(),
                'company' => $this->contact->company,
                'status' => $this->contact->status?->value ?? (string) $this->contact->status,
                'contact_status' => $this->contact->contact_status?->value ?? (string) $this->contact->contact_status,
            ],
            'channel_types' => $channelTypes,
            'deals' => [
                'open' => $openDeals->count(),
                'won' => $won,
                'lost' => $lost,
                'total' => $deals->count(),
                'open_stages' => $openDeals
                    ->map(fn (Deal $d) => $d->status instanceof DealStatus ? $d->status->value : (string) $d->status)
                    ->values()
                    ->all(),
            ],
            'active_contracts' => $contracts->map(function (Contract $contract): array {
                $unit = $contract->unitItem?->item instanceof Unit ? $contract->unitItem->item : null;
                $site = $unit?->site;
                $tenancyStart = null;
                if ($contract->move_in_date !== null) {
                    $tenancyStart = $site !== null
                        ? SiteClock::dateAt($site, $contract->move_in_date)->toDateString()
                        : $contract->move_in_date->toDateString();
                }

                $amount = $contract->unitItem?->price?->amount;
                $currency = (string) ($contract->unitItem?->price?->currency ?? $contract->currency);

                /** @var Delinquency|null $openCase */
                $openCase = $contract->delinquencies->first();

                return [
                    'id' => $contract->id,
                    'status' => $contract->status instanceof ContractStatus
                        ? $contract->status->value
                        : (string) $contract->status,
                    'unit_class' => $unit?->unitClass?->label,
                    'monthly_rate' => is_string($amount) && $currency !== ''
                        ? $this->money($amount, $currency)
                        : null,
                    'tenancy_start' => $tenancyStart,
                    'balance' => $currency !== ''
                        ? $this->money($contract->balanceOwed(), $currency)
                        : null,
                    'delinquency' => $openCase !== null
                        ? [
                            'id' => $openCase->id,
                            'days_overdue' => DelinquencyState::daysOverdue($contract),
                        ]
                        : null,
                ];
            })->values()->all(),
            'balances_by_currency' => $balances,
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
            'deals' => (int) ($payload['deals']['total'] ?? 0),
            'active_contracts' => count($payload['active_contracts']),
            'open_tasks' => count($payload['open_tasks']),
            'charges' => 0,
        ];
    }
}
