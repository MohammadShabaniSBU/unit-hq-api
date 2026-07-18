<?php

namespace App\Models;

use App\Enums\LogChannel;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Commercial product definition. Defines the size and pricing for a category of units.
 *
 * current_price_id is a convenience pointer — authoritative pricing history
 * lives in unit_class_rates + prices.
 *
 * @property int         $id
 * @property string      $code
 * @property string      $label
 * @property float|null  $size
 * @property int|null    $current_price_id
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Price|null                     $currentPrice
 * @property-read Collection<int, Unit>          $units
 * @property-read Collection<int, UnitClassRate> $unitClassRates
 */
class UnitClass extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    protected $fillable = [
        'code',
        'label',
        'size',
        'current_price_id',
    ];

    protected function casts(): array
    {
        return [
            'size'             => 'decimal:2',
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
}
