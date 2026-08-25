<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogChannel;
use App\Enums\SizeGuideMetric;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Operator-edited capacity band. Archive-only. Null site_id is the company
 * default; null unit_class_id is a size-band rule rather than a class.
 *
 * @property int $id
 * @property int|null $site_id
 * @property int|null $unit_class_id
 * @property SizeGuideMetric $metric
 * @property string|null $min_size
 * @property string|null $max_size
 * @property int|null $min_quantity
 * @property int|null $max_quantity
 * @property string|null $notes
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Site|null $site
 * @property-read UnitClass|null $unitClass
 */
class SizeGuide extends Model
{
    use HasFactory, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Facility;
    }

    protected $fillable = [
        'site_id',
        'unit_class_id',
        'metric',
        'min_size',
        'max_size',
        'min_quantity',
        'max_quantity',
        'notes',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'metric' => SizeGuideMetric::class,
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function specificity(): int
    {
        return ($this->site_id !== null ? 2 : 0) + ($this->unit_class_id !== null ? 1 : 0);
    }

    /** @param Builder<SizeGuide> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<SizeGuide> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<UnitClass, $this> */
    public function unitClass(): BelongsTo
    {
        return $this->belongsTo(UnitClass::class);
    }
}
