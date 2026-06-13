<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Line item inside an offer. References a UnitClass (not a specific unit).
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
 * @property int         $unit_class_id
 * @property int         $price_id
 * @property int|null    $discount_id
 * @property string      $label
 * @property string|null $description
 * @property int         $display_order
 * @property Carbon|null $selected_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Offer        $offer
 * @property-read UnitClass    $unitClass
 * @property-read Price        $price
 * @property-read Discount|null $discount
 * @property-read Reservation|null $reservation
 */
class OfferOption extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'offer_id',
        'unit_class_id',
        'price_id',
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

    /** @return BelongsTo<Offer, OfferOption> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<UnitClass, OfferOption> */
    public function unitClass(): BelongsTo
    {
        return $this->belongsTo(UnitClass::class);
    }

    /** @return BelongsTo<Price, OfferOption> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
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
