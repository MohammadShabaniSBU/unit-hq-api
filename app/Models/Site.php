<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A physical storage facility location. Top of the tenant facility hierarchy.
 * Site → Unit (no building layer).
 *
 * Archive-only — never hard-deleted. No global scope: archived sites must
 * still resolve via show / route binding / relations for historical contracts.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $code
 * @property string|null $address
 * @property string|null $address_line_2
 * @property array|null  $location
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $state_region
 * @property int|null    $country_id
 * @property string      $timezone
 * @property string|null $currency   ISO 4217 form prefill only (D1) — not authoritative
 * @property int         $legal_entity_id
 * @property Carbon|null $archived_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Country|null                   $country
 * @property-read LegalEntity                    $legalEntity
 * @property-read Collection<int, Unit>          $units
 * @property-read Collection<int, UnitClassRate> $unitClassRates
 * @property-read Collection<int, SiteMap>       $siteMaps
 * @property-read Collection<int, SiteSenderIdentity> $senderIdentities
 * @property-read Collection<int, CommunicationAccount> $communicationAccounts
 * @property-read SiteStripeSetting|null         $stripeSetting
 */
class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'address_line_2',
        'location',
        'contact_email',
        'contact_phone',
        'city',
        'postal_code',
        'state_region',
        'country_id',
        'timezone',
        'currency',
        'legal_entity_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'location' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<Site> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<Site> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /**
     * Counts blocking archive: active contracts and non-expired pending/confirmed reservations.
     *
     * @return array{active_contracts: int, active_reservations: int}
     */
    public function occupancyBlockCounts(): array
    {
        $unitIds = $this->units()->pluck('id');

        if ($unitIds->isEmpty()) {
            return [
                'active_contracts' => 0,
                'active_reservations' => 0,
            ];
        }

        $activeContracts = Contract::query()
            ->whereIn('status', [
                ContractStatus::Pending->value,
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])
            ->whereHas('items', function (Builder $query) use ($unitIds): void {
                $query
                    ->where('item_type', (new Unit)->getMorphClass())
                    ->whereIn('item_id', $unitIds);
            })
            ->count();

        $activeReservations = Reservation::query()
            ->whereIn('unit_id', $unitIds)
            ->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::Confirmed->value,
            ])
            ->where('expires_at', '>', now())
            ->count();

        return [
            'active_contracts' => $activeContracts,
            'active_reservations' => $activeReservations,
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
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

    /** @return HasMany<SiteMap> */
    public function siteMaps(): HasMany
    {
        return $this->hasMany(SiteMap::class)->orderBy('sort_order');
    }

    /** @return HasMany<SiteSenderIdentity> */
    public function senderIdentities(): HasMany
    {
        return $this->hasMany(SiteSenderIdentity::class);
    }

    /** @return HasMany<CommunicationAccount> */
    public function communicationAccounts(): HasMany
    {
        return $this->hasMany(CommunicationAccount::class);
    }

    /** @return HasOne<SiteStripeSetting, $this> */
    public function stripeSetting(): HasOne
    {
        return $this->hasOne(SiteStripeSetting::class);
    }
}
