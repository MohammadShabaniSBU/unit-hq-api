<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountKind;
use App\Enums\LogChannel;
use App\Models\Concerns\LogsDirtyActivity;
use App\Support\Discounts\DiscountAlignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Admin-defined discount catalogue row. Archive-only; picked onto offers /
 * contracts and compiled at signing (DISC-00 / DISC-01).
 *
 * @property int              $id
 * @property string           $name
 * @property DiscountKind     $kind
 * @property array            $params
 * @property string           $applies_to
 * @property bool             $tracks_rate_changes
 * @property Carbon|null      $archived_at
 * @property int|null         $created_by
 * @property Carbon           $created_at
 * @property Carbon           $updated_at
 *
 * @property-read Collection<int, OfferOption>  $offerOptions
 * @property-read Collection<int, ContractItem> $contractItems
 * @property-read Employee|null                 $creator
 * @property-read int|null                      $offer_options_count
 * @property-read int|null                      $contract_items_count
 */
class Discount extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    protected $fillable = [
        'name',
        'kind',
        'params',
        'applies_to',
        'tracks_rate_changes',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'params' => 'array',
            'tracks_rate_changes' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<Discount> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<Discount> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return HasMany<OfferOption, $this> */
    public function offerOptions(): HasMany
    {
        return $this->hasMany(OfferOption::class);
    }

    /** @return HasMany<ContractItem, $this> */
    public function contractItems(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * Compat bridge until DISC-01 compiler: percent reduces rate; free_time is a no-op.
     */
    public function applyTo(float $amount): float
    {
        if ($this->kind !== DiscountKind::Percent) {
            return round($amount, 2);
        }

        $percent = (string) ($this->params['percent'] ?? '0');
        if (bccomp($percent, '0', 2) <= 0) {
            return round($amount, 2);
        }

        $factor = bcsub('1', bcdiv($percent, '100', 6), 6);

        return (float) bcmul((string) $amount, $factor, 2);
    }

    /** @return array<int, string> */
    public function alignmentWarnings(): array
    {
        return DiscountAlignment::warnings($this->kind, $this->params ?? []);
    }

    public function usageCount(): int
    {
        $offerCount = $this->offer_options_count ?? $this->offerOptions()->count();
        $itemCount = $this->contract_items_count ?? $this->contractItems()->count();

        return (int) $offerCount + (int) $itemCount;
    }
}
