<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Append-only debit entry. Never updated or deleted.
 * Corrections are made by inserting a new charge with reversal_of_charge_id
 * pointing to the original.
 *
 * Overdue is calculated per charge from due_date — not from a net balance sign.
 * is_overdue: due_date < today AND SUM(allocations) < amount.
 *
 * @property int         $id
 * @property int         $lease_id
 * @property int|null    $invoice_id
 * @property string      $charge_type rent|insurance|late_fee|lien_fee|other
 * @property string      $amount      NUMERIC(10,2)
 * @property string      $due_date    Y-m-d
 * @property string|null $description
 * @property int|null    $reversal_of_charge_id
 * @property Carbon      $created_at
 *
 * @property-read Lease                        $lease
 * @property-read Invoice|null                 $invoice
 * @property-read Charge|null                  $reversalOf
 * @property-read Collection<int, Allocation>  $allocations
 */
class Charge extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected array $fillable = [
        'lease_id',
        'invoice_id',
        'charge_type',
        'amount',
        'due_date',
        'description',
        'reversal_of_charge_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'   => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    /** @return BelongsTo<Lease, Charge> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<Invoice, Charge> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Charge, Charge> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'reversal_of_charge_id');
    }

    /** @return HasMany<Allocation> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
