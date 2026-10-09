<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Saved contact filter tree. Counts are evaluated live; nothing stores a member list.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $entity_type
 * @property array{op: string, conditions: list<array<string, mixed>>} $filter
 * @property bool $is_shared
 * @property int|null $created_by
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'entity_type',
        'filter',
        'is_shared',
        'created_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'is_shared' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<Segment>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
