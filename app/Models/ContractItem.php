<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractItemChangeReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A versioned line item on a contract. Amount and currency live on the
 * referenced Price. Versions are superseded (effective_to + successor row),
 * never updated in place (invariant 2b).
 *
 * Polymorphic subject: item_type = unit | insurance.
 *
 * @property int                              $id
 * @property int                              $contract_id
 * @property string                           $item_type
 * @property int                              $item_id
 * @property int                              $price_id
 * @property int|null                         $discount_id
 * @property string|null                      $base_rate
 * @property Carbon|null                      $discount_ends_at
 * @property int|null                         $tax_rate_id
 * @property string|null                      $tax_rate_snapshot
 * @property string|null                      $declared_goods_value
 * @property string|null                      $description
 * @property Carbon                           $effective_from
 * @property Carbon|null                      $effective_to
 * @property int|null                         $supersedes_id
 * @property ContractItemChangeReason|null    $change_reason
 *
 * @property-read Contract         $contract
 * @property-read Price            $price
 * @property-read Discount|null    $discount
 * @property-read TaxRate|null     $taxRate
 * @property-read Unit|Insurance   $item
 * @property-read ContractItem|null $supersedes
 * @property-read ContractItem|null $supersededBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Charge> $charges
 */
class ContractItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'item_type',
        'item_id',
        'price_id',
        'discount_id',
        'base_rate',
        'discount_ends_at',
        'tax_rate_id',
        'tax_rate_snapshot',
        'declared_goods_value',
        'description',
        'effective_from',
        'effective_to',
        'supersedes_id',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'base_rate'             => 'decimal:2',
            'discount_ends_at'      => 'date',
            'tax_rate_snapshot'     => 'decimal:2',
            'declared_goods_value'  => 'decimal:2',
            'effective_from'        => 'date',
            'effective_to'          => 'date',
            'change_reason'         => ContractItemChangeReason::class,
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

    /** @return BelongsTo<ContractItem, ContractItem> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return HasOne<ContractItem, ContractItem> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /**
     * Half-open window: effective_from <= $on < effective_to (or open-ended).
     *
     * @param  Builder<ContractItem>  $q
     * @return Builder<ContractItem>
     */
    public function scopeEffectiveOn(Builder $q, CarbonInterface $on): Builder
    {
        $date = $on->toDateString();

        return $q->where('effective_from', '<=', $date)
            ->where(function (Builder $inner) use ($date): void {
                $inner->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $date);
            });
    }

    /**
     * @param  Builder<ContractItem>  $q
     * @return Builder<ContractItem>
     */
    public function scopeEffectiveBetween(Builder $q, CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        $fromDate = $from->toDateString();

        $q->where(function (Builder $inner) use ($fromDate): void {
            $inner->whereNull('effective_to')
                ->orWhere('effective_to', '>', $fromDate);
        })->where(function (Builder $inner) use ($to): void {
            if ($to === null) {
                return;
            }
            $inner->where('effective_from', '<', $to->toDateString());
        });

        return $q;
    }
}
