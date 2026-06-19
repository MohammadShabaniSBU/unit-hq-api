<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Stores the value of a PropertyDefinition for a specific entity instance.
 * value is always stored as text and cast to the correct type at read time
 * using the parent definition's data_type.
 *
 * @property int    $id
 * @property int    $property_definition_id
 * @property string $propertable_type
 * @property int    $propertable_id
 * @property string $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read PropertyDefinition $definition
 * @property-read \Illuminate\Database\Eloquent\Model $propertable
 */
class PropertyValue extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'property_definition_id',
        'propertable_type',
        'propertable_id',
        'value',
    ];

    /** @return BelongsTo<PropertyDefinition, PropertyValue> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(PropertyDefinition::class, 'property_definition_id');
    }

    /** @return MorphTo */
    public function propertable(): MorphTo
    {
        return $this->morphTo();
    }
}
