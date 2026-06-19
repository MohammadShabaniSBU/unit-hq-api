<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int    $id
 * @property int    $insurance_id
 * @property int    $price_id
 * @property Carbon $created_at
 *
 * @property-read Insurance $insurance
 * @property-read Price     $price
 */
class InsuranceRate extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'insurance_id',
        'price_id',
    ];

    /** @return BelongsTo<Insurance, InsuranceRate> */
    public function insurance(): BelongsTo
    {
        return $this->belongsTo(Insurance::class);
    }

    /** @return BelongsTo<Price, InsuranceRate> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
