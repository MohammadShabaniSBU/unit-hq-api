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
 * @property string      $coverage    NUMERIC(10,2)
 * @property string      $currency    CHAR(3) e.g. EUR
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, InsuranceRate> $rates
 */
class Insurance extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'coverage',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'coverage' => 'decimal:2',
        ];
    }

    /** @return HasMany<InsuranceRate, Insurance> */
    public function rates(): HasMany
    {
        return $this->hasMany(InsuranceRate::class);
    }
}
