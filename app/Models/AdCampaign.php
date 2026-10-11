<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Advertising\AdCampaignStatus;
use App\Support\Auth\Permission;
use Database\Factories\AdCampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Synced ad campaign plus the operator's site mapping.
 * A site-scoped employee sees campaigns mapped to granted sites.
 * Unmapped campaigns stay company-wide.
 *
 * @property int $id
 * @property int $ad_platform_account_id
 * @property string $external_id
 * @property string $name
 * @property AdCampaignStatus $status
 * @property string|null $channel_type
 * @property int|null $site_id
 * @property int|null $unit_class_id
 * @property string|null $utm_campaign_hint
 * @property int|null $mapped_by
 * @property Carbon|null $mapped_at
 * @property Carbon $first_seen_at
 * @property Carbon $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdCampaign extends Model
{
    /** @use HasFactory<AdCampaignFactory> */
    use HasFactory;

    protected $fillable = [
        'ad_platform_account_id',
        'external_id',
        'name',
        'status',
        'channel_type',
        'site_id',
        'unit_class_id',
        'utm_campaign_hint',
        'mapped_by',
        'mapped_at',
        'first_seen_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdCampaignStatus::class,
            'mapped_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, Employee $employee, Permission $permission): void
    {
        $siteIds = $employee->siteIdsFor($permission);

        if ($siteIds === null) {
            return;
        }

        $query->whereIn('site_id', $siteIds);
    }

    /** @return BelongsTo<AdPlatformAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'ad_platform_account_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<UnitClass, $this> */
    public function unitClass(): BelongsTo
    {
        return $this->belongsTo(UnitClass::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function mapper(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'mapped_by');
    }

    /** @return HasMany<AdSpendDaily, $this> */
    public function spendDays(): HasMany
    {
        return $this->hasMany(AdSpendDaily::class);
    }
}
