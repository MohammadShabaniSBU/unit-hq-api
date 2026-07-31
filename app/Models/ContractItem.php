<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
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
 * amount is the line's frozen period charge (snapshot). A per-contract rate
 * override writes here — it never UPDATEs a Price row (invariant #2). price_id
 * only records provenance. tax_rate_id/tax_rate_snapshot freeze the tax
 * version applied at signing.
 *
 * @property int         $id
 * @property int         $contract_id
 * @property string      $item_type   morph alias: 'unit' | 'insurance'
 * @property int         $item_id
 * @property string      $amount      NUMERIC(10,2)
 * @property string      $currency    ISO 4217 snapshot from price at signing
 * @property int|null    $price_id
 * @property int|null    $discount_id
 * @property string|null $base_rate   NUMERIC(10,2)
 * @property Carbon|null $discount_ends_at
 * @property int|null    $tax_rate_id
 * @property string|null $tax_rate_snapshot   NUMERIC(5,2)
 * @property string|null $declared_goods_value NUMERIC(10,2) — unit items only
 * @property string|null $description
 *
 * @property-read Contract         $contract
 * @property-read Price|null       $price
 * @property-read Discount|null    $discount
 * @property-read TaxRate|null     $taxRate
 * @property-read Unit|Insurance   $item
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Charge> $charges
 */
class ContractItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'item_type',
        'item_id',
        'amount',
        'currency',
        'price_id',
        'discount_id',
        'base_rate',
        'discount_ends_at',
        'tax_rate_id',
        'tax_rate_snapshot',
        'declared_goods_value',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount'                => 'decimal:2',
            'base_rate'             => 'decimal:2',
            'discount_ends_at'      => 'date',
            'tax_rate_snapshot'     => 'decimal:2',
            'declared_goods_value'  => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Contract, ContractItem> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Price, ContractItem> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /** @return BelongsTo<Discount, ContractItem> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** @return BelongsTo<TaxRate, ContractItem> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, ContractItem> */
    public function item(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Charge, ContractItem> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }
}
