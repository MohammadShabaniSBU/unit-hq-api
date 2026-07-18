<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * is_available is always derived — never stored as a column.
 * Derived: absence of active contracts + non-expired reservations for this unit.
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
 * @property-read Collection<int, Reservation>   $reservations
 * @property-read Collection<int, ContractItem>  $contractItems
 * @property-read Collection<int, PropertyValue> $propertyValues
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

    /** @return MorphMany<PropertyValue> */
    public function propertyValues(): MorphMany
    {
        return $this->morphMany(PropertyValue::class, 'propertable');
    }

    /** @param Builder<Unit> $query */
    public function scopeWithMapStatus(Builder $query): Builder
    {
        $itemType = $this->getMorphClass();

        return $query->withExists([
            'contractItems as has_active_contract' => function (Builder $contractItemsQuery) use ($itemType): void {
                $contractItemsQuery
                    ->where('item_type', $itemType)
                    ->whereHas('contract', function (Builder $contractQuery): void {
                        $contractQuery->where('status', ContractStatus::Active->value);
                    });
            },
            'reservations as has_active_reservation' => function (Builder $reservationQuery): void {
                $reservationQuery
                    ->whereIn('status', [
                        ReservationStatus::Pending->value,
                        ReservationStatus::Confirmed->value,
                    ])
                    ->where('expires_at', '>', now());
            },
        ]);
    }

    public function deriveStatus(): UnitStatus
    {
        if (! $this->enabled) {
            return UnitStatus::Archived;
        }

        if ($this->has_active_contract ?? $this->hasActiveContract()) {
            return UnitStatus::Occupied;
        }

        if ($this->has_active_reservation ?? $this->hasActiveReservation()) {
            return UnitStatus::Reserved;
        }

        return UnitStatus::Free;
    }

    public function hasActiveContract(): bool
    {
        return $this->contractItems()
            ->where('item_type', $this->getMorphClass())
            ->whereHas('contract', function (Builder $contractQuery): void {
                $contractQuery->where('status', ContractStatus::Active->value);
            })
            ->exists();
    }

    public function hasActiveReservation(): bool
    {
        return $this->reservations()
            ->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::Confirmed->value,
            ])
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** @param Builder<Unit> $query */
    public function scopeReservable(Builder $query): Builder
    {
        $itemType = (new static)->getMorphClass();

        return $query
            ->whereDoesntHave('contractItems', function (Builder $contractItemsQuery) use ($itemType): void {
                $contractItemsQuery
                    ->where('item_type', $itemType)
                    ->whereHas('contract', function (Builder $contractQuery): void {
                        $contractQuery->where('status', ContractStatus::Active->value);
                    });
            })
            ->whereDoesntHave('reservations', function (Builder $reservationQuery): void {
                $reservationQuery
                    ->whereIn('status', [
                        ReservationStatus::Pending->value,
                        ReservationStatus::Confirmed->value,
                    ])
                    ->where('expires_at', '>', now());
            });
    }

    public static function resolveUnitIdForRate(int $unitClassRateId): ?int
    {
        $rate = UnitClassRate::query()->find($unitClassRateId);

        if ($rate === null) {
            return null;
        }

        return static::query()
            ->reservable()
            ->where('unit_class_id', $rate->unit_class_id)
            ->where('site_id', $rate->site_id)
            ->inRandomOrder()
            ->value('id');
    }
}
