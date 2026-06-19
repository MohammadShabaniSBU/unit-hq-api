<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * TBD: discount_type (percentage|fixed_amount) and effective-date rules
 * are not yet fully formalised. This model is a stub pending that decision.
 *
 * @property int         $id
 * @property string|null $code
 * @property string      $label
 * @property string      $discount_type
 * @property string      $value           NUMERIC(10,2) — returned as string
 * @property string|null $effective_from  Y-m-d
 * @property string|null $effective_to    Y-m-d
 * @property Carbon      $created_at
 *
 * @property-read Collection<int, OfferOption> $offerOptions
 */
class Discount extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'code',
        'label',
        'discount_type',
        'value',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'value'          => 'decimal:2',
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
    }

    /** @return HasMany<OfferOption> */
    public function offerOptions(): HasMany
    {
        return $this->hasMany(OfferOption::class);
    }
}
