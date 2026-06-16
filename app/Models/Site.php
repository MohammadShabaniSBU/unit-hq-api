<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A physical storage facility location. Top of the tenant facility hierarchy.
 * Site → Unit (no building layer).
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $address
 * @property array|null  $location
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $city
 * @property string|null $country
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, Unit>          $units
 * @property-read Collection<int, UnitClassRate> $unitClassRates
 */
class Site extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'name',
        'address',
        'location',
        'contact_email',
        'contact_phone',
        'city',
        'country',
    ];

    protected array $casts = [
        'location' => 'array',
    ];

    /** @return HasMany<Unit> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return HasMany<UnitClassRate> */
    public function unitClassRates(): HasMany
    {
        return $this->hasMany(UnitClassRate::class);
    }
}
