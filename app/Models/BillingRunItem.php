<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingRunItemOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-contract outcome within a billing run. Written once; append-only.
 *
 * @property int                     $id
 * @property int                     $billing_run_id
 * @property int                     $contract_id
 * @property BillingRunItemOutcome   $outcome
 * @property int                     $periods_billed
 * @property string|null             $detail
 * @property string|null             $error_message
 * @property array<int, int>|null    $invoice_ids
 * @property string|null             $amount_total
 * @property string|null             $currency
 * @property Carbon                  $created_at
 *
 * @property-read BillingRun $billingRun
 * @property-read Contract   $contract
 */
class BillingRunItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'billing_run_id',
        'contract_id',
        'outcome',
        'periods_billed',
        'detail',
        'error_message',
        'invoice_ids',
        'amount_total',
        'currency',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => BillingRunItemOutcome::class,
            'periods_billed' => 'integer',
            'invoice_ids' => 'array',
            'amount_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BillingRun, BillingRunItem> */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /** @return BelongsTo<Contract, BillingRunItem> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
