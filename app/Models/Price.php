<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Immutable monetary amount. Never update amount/currency/scope/ownership
 * in place. Catalogue prices version via effective_to close + new row;
 * contract-scoped prices carry no window (timing lives on contract_items).
 *
 * @property int              $id
 * @property string|null      $priceable_type
 * @property int|null         $priceable_id
 * @property string           $scope          catalogue|contract
 * @property string           $amount         NUMERIC(10,2)
 * @property string           $currency       ISO 4217
 * @property string|null      $effective_from Y-m-d (required for catalogue)
 * @property string|null      $effective_to   Y-m-d, null = current catalogue
 * @property int|null         $created_by
 * @property Carbon           $created_at
 *
 * @property-read Employee|null                  $creator
 * @property-read UnitClassRate|InsuranceRate|null $priceable
 */
class Price extends Model
{
    use HasFactory;

    public const SCOPE_CATALOGUE = 'catalogue';

    public const SCOPE_CONTRACT = 'contract';

    const UPDATED_AT = null;

    protected $fillable = [
        'priceable_type',
        'priceable_id',
        'scope',
        'amount',
        'currency',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Price $price): void {
            foreach (['amount', 'currency', 'scope', 'priceable_type', 'priceable_id'] as $attr) {
                if ($price->isDirty($attr)) {
                    throw new RuntimeException("Price.{$attr} is immutable.");
                }
            }

            if ($price->isDirty('effective_to') && $price->getOriginal('effective_to') !== null) {
                throw new RuntimeException('Price.effective_to can only be set once.');
            }
        });
    }

    /** @return BelongsTo<Employee, Price> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, Price> */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }
}
