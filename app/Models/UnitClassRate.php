<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Junction: a unit class priced per site. A new row is inserted on each
 * rate change — rows are never updated or deleted.
 *
 * @property int    $id
 * @property int    $unit_class_id
 * @property int    $site_id
 * @property int    $price_id
 * @property Carbon $created_at
 *
 * @property-read UnitClass $unitClass
 * @property-read Site      $site
 * @property-read Price     $price
 */
class UnitClassRate extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'unit_class_id',
        'site_id',
        'price_id',
    ];

    /** @return BelongsTo<UnitClass, UnitClassRate> */
    public function unitClass(): BelongsTo
    {
        return $this->belongsTo(UnitClass::class);
    }

    /** @return BelongsTo<Site, UnitClassRate> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Price, UnitClassRate> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
