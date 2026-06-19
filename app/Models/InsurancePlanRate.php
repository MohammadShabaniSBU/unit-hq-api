<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int    $id
 * @property int    $insurance_plan_id
 * @property int    $price_id
 * @property Carbon $created_at
 *
 * @property-read InsurancePlan $insurancePlan
 * @property-read Price         $price
 */
class InsurancePlanRate extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'insurance_plan_id',
        'price_id',
    ];

    /** @return BelongsTo<InsurancePlan, InsurancePlanRate> */
    public function insurancePlan(): BelongsTo
    {
        return $this->belongsTo(InsurancePlan::class);
    }

    /** @return BelongsTo<Price, InsurancePlanRate> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
