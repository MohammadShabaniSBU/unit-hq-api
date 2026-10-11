<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Advertising\AdPlatform;
use Database\Factories\AdPlatformAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Company-scoped ad account. Site attribution lives on the campaign, not here.
 *
 * @property int $id
 * @property AdPlatform $provider
 * @property string $name
 * @property string $external_account_id
 * @property string|null $login_customer_id
 * @property string|null $currency
 * @property string|null $timezone
 * @property array<string, mixed> $credentials
 * @property bool $is_active
 * @property Carbon|null $last_verified_at
 * @property string|null $last_error
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdPlatformAccount extends Model
{
    /** @use HasFactory<AdPlatformAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'name',
        'external_account_id',
        'login_customer_id',
        'currency',
        'timezone',
        'credentials',
        'is_active',
        'last_verified_at',
        'last_error',
        'archived_at',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AdPlatform::class,
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_verified_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Accounts that sync and delivery may touch. Archived or inactive rows are skipped.
     *
     * @param  Builder<AdPlatformAccount>  $query
     * @return Builder<AdPlatformAccount>
     */
    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /** @return HasMany<AdCampaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }

    /** @return HasMany<AdConversionMapping, $this> */
    public function conversionMappings(): HasMany
    {
        return $this->hasMany(AdConversionMapping::class);
    }

    /** @return HasMany<AdConversionDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AdConversionDelivery::class);
    }
}
