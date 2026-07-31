<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Support\Billing\CurrencyGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Append-only debit entry. Never updated or deleted.
 * Corrections are made by inserting a new charge with reversal_of_charge_id
 * pointing to the original.
 *
 * amount is the gross recorded fact = net_amount + tax_amount (exclusive tax).
 * period_start/period_end is the service window this charge covers (null for
 * one-offs, e.g. deposit). contract_item_id traces the charge back to the
 * line it was generated for — null for contract-level charges like deposit.
 *
 * Overdue is calculated per charge from due_date — not from a net balance sign.
 * is_overdue: due_date < today AND SUM(allocations) < amount.
 *
 * @property int         $id
 * @property int         $contract_id
 * @property int|null    $contract_item_id
 * @property int|null    $billing_period_id
 * @property ChargeType  $charge_type
 * @property string|null $period_start Y-m-d
 * @property string|null $period_end   Y-m-d
 * @property string|null $net_amount   NUMERIC(10,2) — pre-tax
 * @property string      $amount      NUMERIC(10,2) — gross recorded fact
 * @property string      $currency    ISO 4217 snapshot from contract
 * @property string|null $tax_rate_snapshot NUMERIC(5,2)
 * @property string      $tax_amount  NUMERIC(10,2)
 * @property string      $due_date    Y-m-d
 * @property string|null $description
 * @property int|null    $reversal_of_charge_id
 * @property Carbon      $created_at
 *
 * @property-read Contract                     $contract
 * @property-read ContractItem|null            $contractItem
 * @property-read BillingPeriod|null           $billingPeriod
 * @property-read Charge|null                  $reversalOf
 * @property-read Collection<int, Allocation>  $allocations
 */
class Charge extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (Charge $charge): void {
            if ($charge->currency === null || $charge->currency === '') {
                $charge->currency = $charge->contract()->value('currency') ?? 'EUR';
            }

            $contract = $charge->relationLoaded('contract')
                ? $charge->contract
                : Contract::query()->find($charge->contract_id);

            if ($contract !== null) {
                CurrencyGuard::assertMatchesContract($contract, (string) $charge->currency);
            }
        });
    }

    protected $fillable = [
        'contract_id',
        'contract_item_id',
        'billing_period_id',
        'charge_type',
        'period_start',
        'period_end',
        'net_amount',
        'amount',
        'currency',
        'tax_rate_snapshot',
        'tax_amount',
        'due_date',
        'description',
        'reversal_of_charge_id',
    ];

    protected function casts(): array
    {
        return [
            'charge_type'        => ChargeType::class,
            'amount'             => 'decimal:2',
            'net_amount'         => 'decimal:2',
            'tax_rate_snapshot'  => 'decimal:2',
            'tax_amount'         => 'decimal:2',
            'period_start'       => 'date',
            'period_end'         => 'date',
            'due_date'           => 'date',
        ];
    }

    /** @return BelongsTo<Contract, Charge> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<ContractItem, Charge> */
    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    /** @return BelongsTo<BillingPeriod, Charge> */
    public function billingPeriod(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class);
    }

    /** @return BelongsTo<Charge, Charge> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'reversal_of_charge_id');
    }

    /** @return HasMany<Allocation, Charge> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
