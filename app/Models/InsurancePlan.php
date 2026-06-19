<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, InsurancePlanRate> $rates
 */
class InsurancePlan extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    /** @return HasMany<InsurancePlanRate> */
    public function rates(): HasMany
    {
        return $this->hasMany(InsurancePlanRate::class);
    }
}
