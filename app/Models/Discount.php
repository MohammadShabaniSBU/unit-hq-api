<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\LogChannel;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int              $id
 * @property string|null      $code
 * @property string           $label
 * @property DiscountType     $discount_type
 * @property string           $value           NUMERIC(10,2) — returned as string
 * @property int|null         $duration_months null = discount applies for life of contract
 * @property string|null      $effective_from  Y-m-d
 * @property string|null      $effective_to    Y-m-d
 * @property Carbon           $created_at
 *
 * @property-read Collection<int, OfferOption> $offerOptions
 */
class Discount extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    const UPDATED_AT = null;

    protected $fillable = [
        'code',
        'label',
        'discount_type',
        'value',
        'duration_months',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'discount_type'   => DiscountType::class,
            'value'           => 'decimal:2',
            'duration_months' => 'integer',
            'effective_from'  => 'date',
            'effective_to'    => 'date',
        ];
    }

    public function applyTo(float $amount): float
    {
        return match ($this->discount_type) {
            DiscountType::Percentage  => round($amount * (1 - (float) $this->value / 100), 2),
            DiscountType::FixedAmount => max(0.0, round($amount - (float) $this->value, 2)),
        };
    }

    /** @return HasMany<OfferOption> */
    public function offerOptions(): HasMany
    {
        return $this->hasMany(OfferOption::class);
    }
}
