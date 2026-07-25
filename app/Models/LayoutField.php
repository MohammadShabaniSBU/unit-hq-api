<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeEntityType;
use App\Enums\LayoutFieldType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Placement of a native or custom attribute field inside an overview card.
 *
 * @property int                      $id
 * @property int                      $group_id
 * @property AttributeEntityType      $entity_type
 * @property int                      $display_order
 * @property LayoutFieldType          $field_type
 * @property string|null              $native_field_key
 * @property int|null                 $attribute_definition_id
 * @property Carbon                   $created_at
 * @property Carbon                   $updated_at
 *
 * @property-read AttributeGroup           $group
 * @property-read AttributeDefinition|null $attributeDefinition
 */
class LayoutField extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_id',
        'entity_type',
        'display_order',
        'field_type',
        'native_field_key',
        'attribute_definition_id',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => AttributeEntityType::class,
            'display_order' => 'integer',
            'field_type' => LayoutFieldType::class,
        ];
    }

    /** @return BelongsTo<AttributeGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AttributeGroup::class, 'group_id');
    }

    /** @return BelongsTo<AttributeDefinition, $this> */
    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_definition_id');
    }
}
