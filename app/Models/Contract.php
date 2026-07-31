<?php

namespace App\Models;

use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Enums\MoveOutSettlement;
use App\Enums\ProrationMethod;
use App\Enums\TransferBilling;
use App\Models\Concerns\HasNotes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Operational and billing anchor created at contract signing.
 * Every charge, payment, and allocation references a contract.
 *
 * Line items (unit, insurance, etc.) live on ContractItem as a polymorphic
 * collection rather than flat FK columns. Each item carries its own amount.
 *
 * Cadence (billing_interval/_count), billing_anchor_model, proration_method,
 * and deposit_amount are snapshotted from settings at signing — a later
 * settings change never rewrites existing contracts. billing_anchor_date is
 * derived once via App\Support\Billing\BillingMath::resolveAnchorDate, never
 * assigned move_in directly. billed_through is a billing cursor the billing
 * job advances — never cached money (invariant #5).
 *
 * reservation_id is nullable for walk-in contracts that bypass the pipeline.
 *
 * @property int                 $id
 * @property int                 $contact_id
 * @property int|null            $reservation_id
 * @property int|null            $deal_id
 * @property string              $start_date              Y-m-d
 * @property string|null         $end_date                Y-m-d
 * @property BillingInterval     $billing_interval         day|week|month
 * @property int                 $billing_interval_count
 * @property BillingAnchorModel  $billing_anchor_model     anniversary|calendar
 * @property string|null         $billing_anchor_date      Y-m-d
 * @property string|null         $billed_through          Y-m-d — cursor, not cached money
 * @property ProrationMethod     $proration_method         daily|full_period|none
 * @property string|null         $move_in_date             Y-m-d
 * @property string              $deposit_amount           NUMERIC(10,2)
 * @property string              $currency                 ISO 4217 snapshot at signing (invariant 35)
 * @property ContractStatus           $status                   pending|active|notice_given|ended|cancelled
 * @property string|null              $notice_given_on          Y-m-d
 * @property int|null                 $notice_period_days       snapshot at signing (invariant 18)
 * @property string|null              $scheduled_move_out_on    Y-m-d — set at notice
 * @property MoveOutSettlement|null   $move_out_settlement      snapshot at signing (invariant 18)
 * @property TransferBilling          $transfer_billing         snapshot at signing (invariant 18)
 * @property string|null              $move_out_on              Y-m-d — actual, set at ended
 * @property ContractEndedReason|null $ended_reason
 * @property Carbon                   $signed_at
 * @property Carbon                   $created_at
 * @property Carbon                   $updated_at
 *
 * @property-read Contact                          $contact
 * @property-read Reservation|null                 $reservation
 * @property-read Deal|null                        $deal
 * @property-read Collection<int, ContractItem>    $items
 * @property-read ContractItem|null                $unitItem
 * @property-read ContractItem|null                $insuranceItem
 * @property-read Collection<int, BillingPeriod>   $billingPeriods
 * @property-read Collection<int, Charge>          $charges
 * @property-read Collection<int, Payment>         $payments
 * @property-read Collection<int, UnitOccupancy>   $occupancies
 * @property-read Collection<int, ContractTransfer> $transfers
 * @property-read DepositSettlement|null           $depositSettlement
 * @property-read Collection<int, Note>              $notes
 */
class Contract extends Model
{
    use HasFactory, HasNotes;

    protected $fillable = [
        'contact_id',
        'reservation_id',
        'deal_id',
        'start_date',
        'end_date',
        'billing_interval',
        'billing_interval_count',
        'billing_anchor_model',
        'billing_anchor_date',
        'billed_through',
        'proration_method',
        'move_in_date',
        'deposit_amount',
        'currency',
        'status',
        'notice_given_on',
        'notice_period_days',
        'scheduled_move_out_on',
        'move_out_settlement',
        'transfer_billing',
        'move_out_on',
        'ended_reason',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                 => ContractStatus::class,
            'notice_given_on'        => 'date',
            'notice_period_days'     => 'integer',
            'scheduled_move_out_on'  => 'date',
            'move_out_settlement'    => MoveOutSettlement::class,
            'transfer_billing'       => TransferBilling::class,
            'move_out_on'            => 'date',
            'ended_reason'           => ContractEndedReason::class,
            'billing_interval'       => BillingInterval::class,
            'billing_interval_count' => 'integer',
            'billing_anchor_model'   => BillingAnchorModel::class,
            'billing_anchor_date'    => 'date',
            'billed_through'         => 'date',
            'proration_method'       => ProrationMethod::class,
            'move_in_date'           => 'date',
            'deposit_amount'         => 'decimal:2',
            'start_date'             => 'date',
            'end_date'               => 'date',
            'signed_at'              => 'datetime',
        ];
    }

    /**
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return $query->where(function (Builder $q) use ($term, $digits) {
            if ($digits !== '') {
                $q->where('id', $digits)
                    ->orWhere('deal_id', $digits);
            }

            $q->orWhereHas('contact', function (Builder $contactQuery) use ($term) {
                $contactQuery->where(function (Builder $inner) use ($term) {
                    $inner->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('company', 'like', "%{$term}%");
                });
            })->orWhereHas('unitItem', function (Builder $itemQuery) use ($term) {
                $itemQuery->whereIn(
                    'item_id',
                    Unit::query()
                        ->where('unit_number', 'like', "%{$term}%")
                        ->select('id')
                );
            });
        });
    }

    /**
     * Grouped count of contracts per status, honoring the same search filter.
     * Returns every status key (including zero counts), in enum order.
     *
     * @return array<string, int>
     */
    public static function statusCounts(?string $search = null): array
    {
        $raw = static::query()
            ->when($search, fn (Builder $q) => $q->search($search))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status');

        $counts = collect($raw)->mapWithKeys(function (mixed $count, mixed $status) {
            $key = $status instanceof ContractStatus
                ? $status->value
                : (string) $status;

            return [$key => (int) $count];
        });

        return collect(ContractStatus::cases())
            ->mapWithKeys(fn (ContractStatus $case) => [
                $case->value => (int) ($counts[$case->value] ?? 0),
            ])
            ->all();
    }

    /**
     * Base query for a single board column: one status, optional search,
     * contact + unitItem.item.site, sorted by keyset order (updated_at DESC, id DESC).
     *
     * @param  Builder<Contract>  $query
     * @return Builder<Contract>
     */
    public function scopeForBoardColumn(Builder $query, ContractStatus $status, ?string $search = null): Builder
    {
        return $query
            ->where('status', $status->value)
            ->when($search, fn (Builder $q) => $q->search($search))
            ->with([
                'contact',
                'unitItem.price',
                'unitItem.item' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        Unit::class => ['site'],
                    ]);
                },
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /** @return BelongsTo<Contact, Contract> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Reservation, Contract> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Deal, Contract> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return HasMany<ContractItem, Contract> */
    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    /**
     * Item versions effective on $date — exactly one per subject when
     * non-overlap holds.
     *
     * @return Collection<int, ContractItem>
     */
    public function itemsOn(CarbonInterface $date): Collection
    {
        return $this->items()
            ->with('price')
            ->effectiveOn($date)
            ->orderBy('id')
            ->get();
    }

    /** @return HasOne<ContractItem, Contract> */
    public function unitItem(): HasOne
    {
        return $this->hasOne(ContractItem::class)
            ->where('item_type', 'unit')
            ->whereNull('effective_to');
    }

    /** @return HasOne<ContractItem, Contract> */
    public function insuranceItem(): HasOne
    {
        return $this->hasOne(ContractItem::class)
            ->where('item_type', 'insurance')
            ->whereNull('effective_to');
    }

    /** @return HasMany<BillingPeriod, Contract> */
    public function billingPeriods(): HasMany
    {
        return $this->hasMany(BillingPeriod::class);
    }

    /** @return HasMany<Charge, Contract> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Invoice, Contract> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Payment, Contract> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<UnitOccupancy, Contract> */
    public function occupancies(): HasMany
    {
        return $this->hasMany(UnitOccupancy::class);
    }

    /** @return HasMany<ContractTransfer, Contract> */
    public function transfers(): HasMany
    {
        return $this->hasMany(ContractTransfer::class);
    }

    /** @return HasOne<DepositSettlement, Contract> */
    public function depositSettlement(): HasOne
    {
        return $this->hasOne(DepositSettlement::class);
    }

    /**
     * Billing cursor — the date the billing job has advanced through. Set once
     * at signing (full first period, or the stub's anchor) and advanced by the
     * recurring billing job thereafter. Stored, but it's a cursor, not cached
     * money (invariant #5): it never encodes balance or amount.
     */
    public function billedThrough(): ?string
    {
        return $this->billed_through !== null
            ? Carbon::parse($this->billed_through)->toDateString()
            : null;
    }

    /**
     * SUM(charges.amount) − SUM(payments.amount). Query-time only — never stored.
     */
    public function balanceOwed(): string
    {
        $chargesTotal = (float) $this->charges()->sum('amount');
        $paymentsTotal = (float) $this->payments()->sum('amount');

        return number_format($chargesTotal - $paymentsTotal, 2, '.', '');
    }

    /**
     * SUM(payments.amount) − SUM(allocations.amount). Query-time only — never stored.
     */
    public function unallocatedCredit(): string
    {
        $paymentsTotal = (float) $this->payments()->sum('amount');
        $allocatedTotal = (float) Allocation::query()
            ->whereHas('payment', fn ($query) => $query->where('contract_id', $this->id))
            ->sum('amount');

        return number_format(max(0, $paymentsTotal - $allocatedTotal), 2, '.', '');
    }

    /**
     * Sum of unpaid portions of charges past due_date. Query-time only — never stored.
     */
    public function overdueAmount(): string
    {
        $today = Carbon::today()->toDateString();
        $overdue = 0.0;

        $charges = $this->charges()
            ->with('allocations')
            ->where('due_date', '<', $today)
            ->get();

        foreach ($charges as $charge) {
            $remaining = (float) $charge->amount - (float) $charge->allocations->sum('amount');

            if ($remaining > 0) {
                $overdue += $remaining;
            }
        }

        return number_format($overdue, 2, '.', '');
    }

    /**
     * @return array{
     *     billed_through: string|null,
     *     balance_owed: string,
     *     unallocated_credit: string,
     *     overdue_amount: string,
     *     currency: string
     * }
     */
    public function billingSummary(): array
    {
        return [
            'billed_through'     => $this->billedThrough(),
            'balance_owed'       => $this->balanceOwed(),
            'unallocated_credit' => $this->unallocatedCredit(),
            'overdue_amount'     => $this->overdueAmount(),
            'currency'           => (string) $this->currency,
        ];
    }
}
