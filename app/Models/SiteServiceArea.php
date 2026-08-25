<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiteServiceAreaKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Operator-declared catchment for a site. Archive-only.
 *
 * @property int $id
 * @property int $site_id
 * @property SiteServiceAreaKind $kind
 * @property string $value
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site $site
 */
class SiteServiceArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'kind',
        'value',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SiteServiceAreaKind::class,
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param Builder<SiteServiceArea> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<SiteServiceArea> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
