<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeEntityType;
use App\Enums\AttributeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Operator-configurable custom field definition for a pipeline/facility entity.
 *
 * Definitions are never hard-deleted — operators archive them instead.
 *
 * @property int                 $id
 * @property AttributeEntityType $entity_type
 * @property string              $key
 * @property string              $label
 * @property AttributeType       $type
 * @property string|null         $group_name
 * @property int                 $display_order
 * @property bool                $is_required
 * @property bool                $is_promoted
 * @property int                 $usage_count
 * @property string|null         $promoted_column
 * @property Carbon|null         $archived_at
 * @property Carbon              $created_at
 * @property Carbon              $updated_at
 *
 * @property-read Collection<int, AttributeOption> $options
 * @property-read Collection<int, AttributeValue>  $values
 */
class AttributeDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'key',
        'label',
        'type',
        'group_name',
        'display_order',
        'is_required',
        'is_promoted',
        'usage_count',
        'promoted_column',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_type'   => AttributeEntityType::class,
            'type'          => AttributeType::class,
            'display_order' => 'integer',
            'is_required'   => 'boolean',
            'is_promoted'   => 'boolean',
            'usage_count'   => 'integer',
            'archived_at'   => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    /** @return HasMany<AttributeOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class, 'definition_id')->orderBy('display_order');
    }

    /** @return HasMany<AttributeValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'definition_id');
    }
}
