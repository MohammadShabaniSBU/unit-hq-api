<?php

namespace App\Models;

use App\Enums\LogChannel;
use App\Enums\UnitState;
use App\Enums\UnitStatus;
use App\Models\Concerns\LogsDirtyActivity;
use App\Support\Occupancy\Availability;
use App\Support\Time\SiteClock;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Physical unit instance. References its class for commercial attributes
 * and its site for location.
 *
 * actual_* overrides are only populated when a unit physically differs from
 * its class. Billing and listings use class dimensions; surveys use actuals.
 *
 * Availability is always derived from unit_occupancies / unit_holds —
 * never stored as a column (invariants 5 / 36).
 *
 * @property int        $id
 * @property int        $site_id
 * @property int        $unit_class_id
 * @property string     $unit_number
 * @property float|null  $actual_width
 * @property float|null  $actual_depth
 * @property float|null  $actual_height
 * @property string|null $note
 * @property bool        $enabled
 * @property Carbon      $created_at
 * @property Carbon     $updated_at
 *
 * @property-read Site                           $site
 * @property-read UnitClass                      $unitClass
 * @property-read Collection<int, Reservation>     $reservations
 * @property-read Collection<int, ContractItem>    $contractItems
 * @property-read Collection<int, UnitOccupancy>   $occupancies
 * @property-read UnitOccupancy|null               $currentOccupancy
 * @property-read Collection<int, UnitHold>        $holds
 */
class Unit extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    protected $fillable = [
        'site_id',
        'unit_class_id',
        'unit_number',
        'actual_width',
        'actual_depth',
        'actual_height',
        'note',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'actual_width'  => 'decimal:2',
            'actual_depth'  => 'decimal:2',
            'actual_height' => 'decimal:2',
            'enabled'       => 'boolean',
        ];
    }

    /** @return BelongsTo<Site, Unit> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<UnitClass, Unit> */
    public function unitClass(): BelongsTo
    {
        return $this->belongsTo(UnitClass::class);
    }

    /** @return HasMany<Reservation> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return MorphMany<ContractItem, Unit> */
    public function contractItems(): MorphMany
    {
        return $this->morphMany(ContractItem::class, 'item');
    }

    /** @return HasMany<UnitOccupancy, Unit> */
    public function occupancies(): HasMany
    {
        return $this->hasMany(UnitOccupancy::class);
    }

    /** @return HasOne<UnitOccupancy, Unit> */
    public function currentOccupancy(): HasOne
    {
        return $this->hasOne(UnitOccupancy::class)->whereNull('ended_on');
    }

    /** @return HasMany<UnitHold, Unit> */
    public function holds(): HasMany
    {
        return $this->hasMany(UnitHold::class);
    }

    /**
     * Units available on the given civil date (fact tables only).
     *
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeAvailableOn(Builder $query, CarbonInterface $on): Builder
    {
        return Availability::scopeAvailableOn($query, $on);
    }

    /**
     * Units available for the half-open range [from, to).
     *
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeAvailableBetween(
        Builder $query,
        CarbonInterface $from,
        ?CarbonInterface $to,
    ): Builder {
        return Availability::scopeAvailableBetween($query, $from, $to);
    }

    /**
     * @deprecated Use availableOn($on) — kept as an alias for call-site migration.
     *
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeReservable(Builder $query, CarbonInterface $on): Builder
    {
        return Availability::scopeAvailableOn($query, $on);
    }

    public function stateOn(CarbonInterface $on): UnitState
    {
        return Availability::stateOn($this->id, $on);
    }

    public function isAvailableOn(CarbonInterface $on): bool
    {
        return Availability::isAvailable($this->id, $on);
    }

    /**
     * Bridge to legacy UnitStatus for clients still reading `status`.
     * Full inventory state is on `state` (UnitState).
     */
    public function deriveStatus(?CarbonInterface $on = null): UnitStatus
    {
        if (! $this->enabled) {
            return UnitStatus::Archived;
        }

        if ($on === null) {
            $this->loadMissing('site');
            $on = SiteClock::today($this->site);
        }

        return match ($this->stateOn($on)) {
            UnitState::Occupied => UnitStatus::Occupied,
            UnitState::Reserved => UnitStatus::Reserved,
            default => UnitStatus::Free,
        };
    }

    public static function resolveUnitIdForRate(int $unitClassRateId): ?int
    {
        $rate = UnitClassRate::query()->with('site')->find($unitClassRateId);

        if ($rate === null || $rate->site === null) {
            return null;
        }

        $on = SiteClock::today($rate->site);

        return static::query()
            ->availableOn($on)
            ->where('unit_class_id', $rate->unit_class_id)
            ->where('site_id', $rate->site_id)
            ->where('enabled', true)
            ->inRandomOrder()
            ->value('id');
    }
}
