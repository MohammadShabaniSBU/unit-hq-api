<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepositPayoutStatus;
use App\Enums\DepositSettlementOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Operator intent at vacate for resolving the signing deposit snapshot.
 * Amount/currency are event snapshots (architecture-pricing §1) — no price_id.
 * payout_status stays pending until S07 executes the payout.
 *
 * @property int                      $id
 * @property int                      $contract_id
 * @property DepositSettlementOutcome $outcome
 * @property string                   $deposit_amount
 * @property string                   $refunded_amount
 * @property string                   $currency
 * @property DepositPayoutStatus      $payout_status
 * @property Carbon|null              $paid_at
 * @property int|null                 $created_by
 * @property Carbon                   $created_at
 * @property Carbon                   $updated_at
 *
 * @property-read Contract                              $contract
 * @property-read Employee|null                         $createdBy
 * @property-read Collection<int, DepositSettlementLine> $lines
 */
class DepositSettlement extends Model
{
    protected $fillable = [
        'contract_id',
        'outcome',
        'deposit_amount',
        'refunded_amount',
        'currency',
        'payout_status',
        'paid_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'outcome'         => DepositSettlementOutcome::class,
            'payout_status'   => DepositPayoutStatus::class,
            'deposit_amount'  => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_at'         => 'datetime',
        ];
    }

    /** @return BelongsTo<Contract, DepositSettlement> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Employee, DepositSettlement> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<DepositSettlementLine, DepositSettlement> */
    public function lines(): HasMany
    {
        return $this->hasMany(DepositSettlementLine::class);
    }
}
