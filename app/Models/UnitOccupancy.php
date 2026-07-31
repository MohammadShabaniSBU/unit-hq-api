<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fact: a contract occupies a unit over a civil-date range [started_on, ended_on).
 * NULL ended_on means still occupied. Availability is derived from these rows —
 * this table is not a cached is_available flag (invariant 5).
 *
 * @property int         $id
 * @property int         $unit_id
 * @property int         $contract_id
 * @property int|null    $contract_item_id
 * @property string      $started_on   Y-m-d
 * @property string|null $ended_on     Y-m-d — NULL = open-ended
 * @property string|null $ended_reason vacated|transferred_out|terminated (S02)
 * @property int|null    $created_by
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Unit              $unit
 * @property-read Contract          $contract
 * @property-read ContractItem|null $contractItem
 * @property-read Employee|null     $createdBy
 */
class UnitOccupancy extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'contract_id',
        'contract_item_id',
        'started_on',
        'ended_on',
        'ended_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on'   => 'date',
        ];
    }

    /** @return BelongsTo<Unit, UnitOccupancy> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Contract, UnitOccupancy> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<ContractItem, UnitOccupancy> */
    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    /** @return BelongsTo<Employee, UnitOccupancy> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
