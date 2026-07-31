<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferPricingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit record of a unit transfer on a contract. Redundant with occupancy
 * and item rows by design — states operator intent in one place.
 *
 * @property int                 $id
 * @property int                 $contract_id
 * @property int                 $from_unit_id
 * @property int                 $to_unit_id
 * @property int                 $from_contract_item_id
 * @property int                 $to_contract_item_id
 * @property string              $transfer_date
 * @property TransferPricingMode $pricing_mode
 * @property string|null         $reason
 * @property int|null            $created_by
 * @property Carbon              $created_at
 * @property Carbon              $updated_at
 *
 * @property-read Contract     $contract
 * @property-read Unit         $fromUnit
 * @property-read Unit         $toUnit
 * @property-read ContractItem $fromContractItem
 * @property-read ContractItem $toContractItem
 * @property-read Employee|null $createdBy
 */
class ContractTransfer extends Model
{
    protected $fillable = [
        'contract_id',
        'from_unit_id',
        'to_unit_id',
        'from_contract_item_id',
        'to_contract_item_id',
        'transfer_date',
        'pricing_mode',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'pricing_mode'  => TransferPricingMode::class,
        ];
    }

    /** @return BelongsTo<Contract, ContractTransfer> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Unit, ContractTransfer> */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    /** @return BelongsTo<Unit, ContractTransfer> */
    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }

    /** @return BelongsTo<ContractItem, ContractTransfer> */
    public function fromContractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'from_contract_item_id');
    }

    /** @return BelongsTo<ContractItem, ContractTransfer> */
    public function toContractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'to_contract_item_id');
    }

    /** @return BelongsTo<Employee, ContractTransfer> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
