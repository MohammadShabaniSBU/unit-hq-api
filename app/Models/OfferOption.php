<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Line item inside an offer. References a UnitClassRate snapshot so site,
 * unit class, and price remain fixed even if rates change later.
 * At most one option per offer may have selected_at populated — enforced by
 * a partial unique index on (offer_id) WHERE selected_at IS NOT NULL.
 *
 * When selected, three things happen in a single transaction:
 *   1. selected_at is written here
 *   2. Offer.status → accepted
 *   3. A Reservation is created referencing this option
 *
 * @property int         $id
 * @property int         $offer_id
 * @property int         $unit_class_rate_id
 * @property int|null    $unit_id
 * @property int|null    $discount_id
 * @property string      $label
 * @property string|null $description
 * @property int         $display_order
 * @property Carbon|null $selected_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Offer             $offer
 * @property-read UnitClassRate     $unitClassRate
 * @property-read Unit|null         $unit
 * @property-read Discount|null     $discount
 * @property-read Reservation|null  $reservation
 */
class OfferOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'unit_class_rate_id',
        'unit_id',
        'discount_id',
        'label',
        'description',
        'display_order',
        'selected_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_at'   => 'datetime',
            'display_order' => 'integer',
        ];
    }

    /** @return array<int, string> */
    public static function unitClassRateEagerLoads(): array
    {
        return [
            'unitClassRate.unitClass',
            'unitClassRate.site',
            'unitClassRate.price',
            'unit',
        ];
    }

    /** @return BelongsTo<Offer, OfferOption> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<UnitClassRate, OfferOption> */
    public function unitClassRate(): BelongsTo
    {
        return $this->belongsTo(UnitClassRate::class);
    }

    /** @return BelongsTo<Unit, OfferOption> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Discount, OfferOption> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** @return HasOne<Reservation> */
    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class);
    }
}
