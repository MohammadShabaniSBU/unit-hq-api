<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Commercial product definition. Defines the nominal dimensions, amenities,
 * tier, and pricing for a category of units.
 *
 * Billing and listings use class dimensions. Physical units may override
 * dimensions via actual_* columns (surveys and compliance use those actuals).
 *
 * current_price_id is a convenience pointer — authoritative pricing history
 * lives in unit_class_rates + prices.
 *
 * @property int         $id
 * @property string      $code_slug
 * @property string      $label
 * @property string      $tier
 * @property float       $width      nominal, metres
 * @property float       $depth      nominal, metres
 * @property float       $height     nominal, metres
 * @property array|null  $amenities
 * @property int|null    $current_price_id
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Price|null                     $currentPrice
 * @property-read Collection<int, Unit>          $units
 * @property-read Collection<int, UnitClassRate> $unitClassRates
 * @property-read Collection<int, OfferOption>   $offerOptions
 */
class UnitClass extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'code_slug',
        'label',
        'tier',
        'width',
        'depth',
        'height',
        'amenities',
        'current_price_id',
    ];

    protected function casts(): array
    {
        return [
            'amenities'        => 'array',
            'width'            => 'decimal:2',
            'depth'            => 'decimal:2',
            'height'           => 'decimal:2',
            'current_price_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Price, UnitClass> */
    public function currentPrice(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'current_price_id');
    }

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

    /** @return HasMany<OfferOption> */
    public function offerOptions(): HasMany
    {
        return $this->hasMany(OfferOption::class);
    }
}
