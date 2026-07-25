<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeEntityType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Overview card (group of layout fields) for an entity type.
 *
 * @property int                 $id
 * @property AttributeEntityType $entity_type
 * @property string              $key
 * @property string              $label
 * @property int                 $display_order
 * @property bool                $is_system
 * @property Carbon              $created_at
 * @property Carbon              $updated_at
 *
 * @property-read Collection<int, LayoutField> $fields
 */
class AttributeGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'key',
        'label',
        'display_order',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => AttributeEntityType::class,
            'display_order' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    /** @return HasMany<LayoutField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(LayoutField::class, 'group_id')->orderBy('display_order');
    }
}
