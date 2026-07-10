<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $code ISO 3166-1 alpha-2
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Collection<int, Site> $sites
 */
class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
    ];

    /** @return HasMany<Site> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
