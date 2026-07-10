<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;

/**
 * A single line item on a contract. Uses a polymorphic relation so that
 * different resource types (Unit, Insurance, etc.) can be attached without
 * adding new FK columns to the contracts table.
 *
 * For a standard rental contract:
 *   - one item with item_type = 'unit'      → links to units table
 *   - one item with item_type = 'insurance' → links to insurances table
 *
 * @property int    $id
 * @property int    $contract_id
 * @property string $item_type   morph alias: 'unit' | 'insurance'
 * @property int    $item_id
 * @property string $rate        NUMERIC(10,2)
 *
 * @property-read Contract         $contract
 * @property-read Unit|Insurance   $item
 */
class ContractItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'item_type',
        'item_id',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Contract, ContractItem> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, ContractItem> */
    public function item(): MorphTo
    {
        return $this->morphTo();
    }
}
