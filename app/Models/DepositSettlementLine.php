<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links a deposit settlement to the ledger charge that realises a deduction
 * or refund line. Amount/currency are event snapshots.
 *
 * @property int         $id
 * @property int         $deposit_settlement_id
 * @property int         $charge_id
 * @property string      $amount
 * @property string      $currency
 * @property string      $reason
 * @property Carbon|null $created_at
 *
 * @property-read DepositSettlement $depositSettlement
 * @property-read Charge            $charge
 */
class DepositSettlementLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'deposit_settlement_id',
        'charge_id',
        'amount',
        'currency',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<DepositSettlement, DepositSettlementLine> */
    public function depositSettlement(): BelongsTo
    {
        return $this->belongsTo(DepositSettlement::class);
    }

    /** @return BelongsTo<Charge, DepositSettlementLine> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
