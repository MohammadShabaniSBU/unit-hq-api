<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Models\Concerns\HasNotes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Operational and billing anchor created at contract signing.
 * Every charge, payment, and allocation references a contract.
 *
 * Line items (unit, insurance, etc.) live on ContractItem as a polymorphic
 * collection rather than flat FK columns. Each item carries its own rate.
 *
 * reservation_id is nullable for walk-in contracts that bypass the pipeline.
 *
 * @property int            $id
 * @property int            $contact_id
 * @property int|null       $reservation_id
 * @property int|null       $deal_id
 * @property string         $start_date     Y-m-d
 * @property string|null    $end_date       Y-m-d
 * @property ContractStatus $status         active|moved_out|terminated|expired
 * @property Carbon         $signed_at
 * @property Carbon         $created_at
 * @property Carbon         $updated_at
 *
 * @property-read Contact                          $contact
 * @property-read Reservation|null                 $reservation
 * @property-read Deal|null                        $deal
 * @property-read Collection<int, ContractItem>    $items
 * @property-read ContractItem|null                $unitItem
 * @property-read ContractItem|null                $insuranceItem
 * @property-read Collection<int, Invoice>         $invoices
 * @property-read Collection<int, Charge>          $charges
 * @property-read Collection<int, Payment>         $payments
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
        'status',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'     => ContractStatus::class,
            'start_date' => 'date',
            'end_date'   => 'date',
            'signed_at'  => 'datetime',
        ];
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

    /** @return HasOne<ContractItem, Contract> */
    public function unitItem(): HasOne
    {
        return $this->hasOne(ContractItem::class)->where('item_type', 'unit');
    }

    /** @return HasOne<ContractItem, Contract> */
    public function insuranceItem(): HasOne
    {
        return $this->hasOne(ContractItem::class)->where('item_type', 'insurance');
    }

    /** @return HasMany<Invoice, Contract> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Charge, Contract> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Payment, Contract> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Max non-void invoice billing_period_end (Space Manager "Charged To" analogue).
     * Query-time only — never stored.
     */
    public function billedThrough(): ?string
    {
        $end = $this->invoices()
            ->where('status', '!=', 'void')
            ->max('billing_period_end');

        return $end !== null ? Carbon::parse($end)->toDateString() : null;
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

    /** @return array{billed_through: string|null, balance_owed: string, unallocated_credit: string, overdue_amount: string} */
    public function billingSummary(): array
    {
        return [
            'billed_through'     => $this->billedThrough(),
            'balance_owed'       => $this->balanceOwed(),
            'unallocated_credit' => $this->unallocatedCredit(),
            'overdue_amount'     => $this->overdueAmount(),
        ];
    }
}
