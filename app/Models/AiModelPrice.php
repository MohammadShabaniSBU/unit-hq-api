<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Effective-dated AI model price catalogue. Never UPDATE rates in place —
 * insert a new row and close the previous effective_to (mirrors Price / TaxRate).
 *
 * @property int         $id
 * @property string      $provider
 * @property string      $model
 * @property string      $input_per_mtok
 * @property string|null $cached_input_per_mtok
 * @property string      $output_per_mtok
 * @property string      $currency
 * @property Carbon      $effective_from
 * @property Carbon|null $effective_to
 */
class AiModelPrice extends Model
{
    protected $fillable = [
        'provider',
        'model',
        'input_per_mtok',
        'cached_input_per_mtok',
        'output_per_mtok',
        'currency',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'input_per_mtok' => 'decimal:4',
            'cached_input_per_mtok' => 'decimal:4',
            'output_per_mtok' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AiModelPrice $price): void {
            foreach (['provider', 'model', 'input_per_mtok', 'cached_input_per_mtok', 'output_per_mtok', 'currency', 'effective_from'] as $attr) {
                if ($price->isDirty($attr)) {
                    throw new RuntimeException("AiModelPrice.{$attr} is immutable.");
                }
            }

            if ($price->isDirty('effective_to') && $price->getOriginal('effective_to') !== null) {
                throw new RuntimeException('AiModelPrice.effective_to can only be set once.');
            }
        });
    }

    /**
     * @param  Builder<AiModelPrice>  $query
     * @return Builder<AiModelPrice>
     */
    public function scopeActiveFor(
        Builder $query,
        string $provider,
        string $model,
        ?string $date = null,
    ): Builder {
        $date ??= Carbon::today()->toDateString();

        return $query
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            });
    }

    /**
     * Publish a new price version, closing any open previous row.
     *
     * @param  array{
     *     input_per_mtok: numeric-string|float|int,
     *     cached_input_per_mtok?: numeric-string|float|int|null,
     *     output_per_mtok: numeric-string|float|int,
     *     currency?: string,
     *     effective_from: string,
     * }  $attributes
     */
    public static function publish(string $provider, string $model, array $attributes): self
    {
        $effectiveFrom = Carbon::parse($attributes['effective_from'])->toDateString();

        return DB::transaction(function () use ($provider, $model, $attributes, $effectiveFrom): self {
            $previous = static::query()
                ->where('provider', $provider)
                ->where('model', $model)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($previous !== null) {
                $closeTo = Carbon::parse($effectiveFrom)->subDay()->toDateString();
                $previous->effective_to = $closeTo;
                $previous->save();
            }

            return static::query()->create([
                'provider' => $provider,
                'model' => $model,
                'input_per_mtok' => $attributes['input_per_mtok'],
                'cached_input_per_mtok' => $attributes['cached_input_per_mtok'] ?? null,
                'output_per_mtok' => $attributes['output_per_mtok'],
                'currency' => $attributes['currency'] ?? 'USD',
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);
        });
    }
}
