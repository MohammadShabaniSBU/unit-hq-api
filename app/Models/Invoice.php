<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Groups charges for a billing period. Invoices are a view over charges —
 * not the atomic unit of money. The true paid/unpaid state is derived from
 * allocations, not from the status column here.
 *
 * @property int         $id
 * @property int         $contract_id
 * @property string      $billing_period_start Y-m-d
 * @property string      $billing_period_end   Y-m-d
 * @property string      $status               draft|issued|paid|void
 * @property Carbon|null $issued_at
 * @property Carbon      $created_at
 *
 * @property-read Contract                 $contract
 * @property-read Collection<int, Charge>  $charges
 */
class Invoice extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'contract_id',
        'billing_period_start',
        'billing_period_end',
        'status',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end'   => 'date',
            'issued_at'            => 'datetime',
        ];
    }

    /** @return BelongsTo<Contract, Invoice> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return HasMany<Charge, Invoice> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }
}
