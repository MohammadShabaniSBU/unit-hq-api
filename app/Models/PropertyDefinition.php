<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Defines an operator-configurable custom field for a specific entity type.
 * entity_type holds the morph class string (or alias from Relation::morphMap).
 *
 * data_type controls how stored text values are cast at read time:
 *   text | integer | decimal | boolean | date | select
 *
 * @property int         $id
 * @property string      $entity_type
 * @property string      $key
 * @property string      $label
 * @property string      $data_type
 * @property array|null  $options      allowed values for select type
 * @property bool        $required
 * @property int         $display_order
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, PropertyValue> $values
 */
class PropertyDefinition extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'entity_type',
        'key',
        'label',
        'data_type',
        'options',
        'required',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'options'       => 'array',
            'required'      => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /** @return HasMany<PropertyValue> */
    public function values(): HasMany
    {
        return $this->hasMany(PropertyValue::class);
    }
}
