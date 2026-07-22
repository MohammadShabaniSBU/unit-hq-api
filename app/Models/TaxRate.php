<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogChannel;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Selectable tax-rate catalog, effective-dated & immutable — mirrors Price
 * (03-pricing.md). A rate change is always: insert a new row with the same
 * code + a new effective_from, then close the previous version by setting
 * its effective_to. Never UPDATE rate in place.
 *
 * Applied rates are snapshotted onto contract_items/charges, so this table's
 * effective dating exists for scheduling and audit — not to protect signed
 * contracts (the snapshot already does that).
 *
 * @property int         $id
 * @property string      $name
 * @property string      $code           stable key grouping versions: standard|ipt|zero|reduced
 * @property string      $rate           NUMERIC(5,2) — percentage, e.g. 20.00
 * @property string|null $jurisdiction   e.g. GB
 * @property bool         $is_default
 * @property string      $effective_from Y-m-d
 * @property string|null $effective_to   Y-m-d — null = current version
 * @property int         $created_by
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class TaxRate extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    protected $fillable = [
        'name',
        'code',
        'rate',
        'jurisdiction',
        'is_default',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate'           => 'decimal:2',
            'is_default'     => 'boolean',
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
    }

    /**
     * @param  Builder<TaxRate>  $query
     * @return Builder<TaxRate>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }

    /**
     * The active version of $code at $date (defaults to today).
     *
     * @param  Builder<TaxRate>  $query
     * @return Builder<TaxRate>
     */
    public function scopeActiveForCode(Builder $query, string $code, ?string $date = null): Builder
    {
        $date ??= Carbon::today()->toDateString();

        return $query
            ->where('code', $code)
            ->where('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            });
    }
}
