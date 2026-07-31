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
 * Static junction: an insurance product priced at a site. Created once per
 * pairing; catalogue timing lives on prices (scope=catalogue).
 *
 * @property int         $id
 * @property int         $insurance_id
 * @property int|null    $site_id
 * @property Carbon      $created_at
 *
 * @property-read Insurance $insurance
 * @property-read Site|null $site
 * @property-read Price|null $price  current catalogue price
 */
class InsuranceRate extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'insurance_id',
        'site_id',
    ];

    /** @return BelongsTo<Insurance, InsuranceRate> */
    public function insurance(): BelongsTo
    {
        return $this->belongsTo(Insurance::class);
    }

    /** @return BelongsTo<Site, InsuranceRate> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return MorphMany<Price, InsuranceRate> */
    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }

    /**
     * Current catalogue price for this pairing.
     *
     * @return MorphOne<Price, InsuranceRate>
     */
    public function price(): MorphOne
    {
        return $this->morphOne(Price::class, 'priceable')
            ->where('scope', Price::SCOPE_CATALOGUE)
            ->whereNull('effective_to');
    }
}
