<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HoldType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fact: a unit is held/blocked over a civil-date range [starts_on, ends_on).
 * NULL ends_on means open-ended. released_at early-releases without deleting
 * (append-only spirit). Availability is derived — not a cached flag (inv 5/36).
 *
 * @property int         $id
 * @property int         $unit_id
 * @property HoldType    $hold_type
 * @property int|null    $reservation_id
 * @property string      $starts_on   Y-m-d
 * @property string|null $ends_on     Y-m-d — NULL = open-ended
 * @property Carbon|null $released_at
 * @property string|null $reason
 * @property int|null    $created_by
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Unit              $unit
 * @property-read Reservation|null  $reservation
 * @property-read Employee|null     $createdBy
 */
class UnitHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'hold_type',
        'reservation_id',
        'starts_on',
        'ends_on',
        'released_at',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'hold_type'   => HoldType::class,
            'starts_on'   => 'date',
            'ends_on'     => 'date',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Unit, UnitHold> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Reservation, UnitHold> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Employee, UnitHold> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
