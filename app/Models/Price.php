<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Immutable monetary amount record. Never updated in place.
 * A rate change means inserting a new Price row and closing the old one
 * by setting effective_to. effective_to = null means this is the current price.
 *
 * @property int         $id
 * @property string      $amount         NUMERIC(10,2) — returned as string by some drivers
 * @property string      $currency       ISO 4217, 3 chars
 * @property string      $billing_period monthly|weekly|annual
 * @property string      $effective_from Y-m-d
 * @property string|null $effective_to   Y-m-d, null = current
 * @property int         $created_by
 * @property Carbon      $created_at
 *
 * @property-read Employee                       $creator
 * @property-read Collection<int, UnitClassRate> $unitClassRates
 */
class Price extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'amount',
        'currency',
        'billing_period',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
    }

    /** @return BelongsTo<Employee, Price> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<UnitClassRate> */
    public function unitClassRates(): HasMany
    {
        return $this->hasMany(UnitClassRate::class);
    }

}
