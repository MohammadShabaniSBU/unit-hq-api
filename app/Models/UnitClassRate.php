<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Static junction: a unit class priced at a site. Created once per pairing;
 * never versioned. Catalogue timing lives on prices (scope=catalogue).
 *
 * @property int    $id
 * @property int    $unit_class_id
 * @property int    $site_id
 * @property Carbon $created_at
 *
 * @property-read UnitClass $unitClass
 * @property-read Site      $site
 * @property-read Price|null $price  current catalogue price
 */
class UnitClassRate extends Model
{
    use HasFactory;
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    const UPDATED_AT = null;

    protected $fillable = [
        'unit_class_id',
        'site_id',
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

    /** @return MorphMany<Price, UnitClassRate> */
    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }

    /**
     * Current catalogue price for this pairing.
     *
     * @return MorphOne<Price, UnitClassRate>
     */
    public function price(): MorphOne
    {
        return $this->morphOne(Price::class, 'priceable')
            ->where('scope', Price::SCOPE_CATALOGUE)
            ->whereNull('effective_to');
    }
}
