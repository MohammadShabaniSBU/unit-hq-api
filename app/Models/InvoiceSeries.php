<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceSeriesKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gapless invoice number series scoped to a legal entity.
 *
 * Archive-only — never hard-deleted. Numbers are allocated via
 * {@see \App\Support\Fiscal\InvoiceNumbering::allocate} inside the caller's
 * issue transaction.
 *
 * @property int               $id
 * @property int               $legal_entity_id
 * @property string            $code
 * @property InvoiceSeriesKind $kind
 * @property int               $next_number
 * @property bool              $is_default
 * @property Carbon|null       $archived_at
 * @property Carbon            $created_at
 * @property Carbon            $updated_at
 *
 * @property-read LegalEntity                   $legalEntity
 * @property-read Collection<int, Invoice>      $invoices
 */
class InvoiceSeries extends Model
{
    use HasFactory;

    protected $table = 'invoice_series';

    protected $fillable = [
        'legal_entity_id',
        'code',
        'kind',
        'next_number',
        'is_default',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InvoiceSeriesKind::class,
            'next_number' => 'integer',
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<InvoiceSeries> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<InvoiceSeries> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** True once any fiscal invoice has been issued under this series. */
    public function hasIssuedInvoices(): bool
    {
        return $this->invoices()->exists();
    }

    /**
     * Ensure the three default series (ordinary / simplified / rectificative)
     * exist for the entity for the current calendar year.
     */
    public static function ensureDefaultsFor(LegalEntity $entity): void
    {
        $year = (int) now()->format('Y');

        $defaults = [
            ['code' => "F{$year}", 'kind' => InvoiceSeriesKind::Ordinary],
            ['code' => "S{$year}", 'kind' => InvoiceSeriesKind::Simplified],
            ['code' => "R{$year}", 'kind' => InvoiceSeriesKind::Rectificative],
        ];

        foreach ($defaults as $default) {
            $exists = static::query()
                ->where('legal_entity_id', $entity->id)
                ->where('code', $default['code'])
                ->whereNull('archived_at')
                ->exists();

            if ($exists) {
                continue;
            }

            $hasDefault = static::query()
                ->where('legal_entity_id', $entity->id)
                ->where('kind', $default['kind'])
                ->where('is_default', true)
                ->whereNull('archived_at')
                ->exists();

            static::query()->create([
                'legal_entity_id' => $entity->id,
                'code' => $default['code'],
                'kind' => $default['kind'],
                'next_number' => 1,
                'is_default' => ! $hasDefault,
                'archived_at' => null,
            ]);
        }
    }
}
