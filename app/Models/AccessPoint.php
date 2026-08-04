<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessPointType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Mapped provider lock/gate/zone at a site (or unit door).
 *
 * @property int                  $id
 * @property int                  $access_provider_account_id
 * @property int                  $site_id
 * @property int|null             $unit_id
 * @property AccessPointType      $point_type
 * @property string               $provider_point_id
 * @property string               $label
 * @property Carbon|null          $archived_at
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 *
 * @property-read AccessProviderAccount $accessProviderAccount
 * @property-read Site            $site
 * @property-read Unit|null       $unit
 */
class AccessPoint extends Model
{
    use HasFactory;
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    protected $fillable = [
        'access_provider_account_id',
        'site_id',
        'unit_id',
        'point_type',
        'provider_point_id',
        'label',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'point_type' => AccessPointType::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccessProviderAccount, AccessPoint> */
    public function accessProviderAccount(): BelongsTo
    {
        return $this->belongsTo(AccessProviderAccount::class);
    }

    /** @return BelongsTo<Site, AccessPoint> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Unit, AccessPoint> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<AccessGrant, AccessPoint> */
    public function grants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    /** @param  Builder<AccessPoint>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->archived_at !== null) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }
}
