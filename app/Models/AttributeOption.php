<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Choice for a select/multiselect attribute definition.
 *
 * @property int    $id
 * @property int    $definition_id
 * @property string $label
 * @property int    $display_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read AttributeDefinition $definition
 */
class AttributeOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'definition_id',
        'label',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    /** @return BelongsTo<AttributeDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'definition_id');
    }

    /** @return BelongsToMany<AttributeValue, $this> */
    public function values(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'attribute_value_options',
            'attribute_option_id',
            'attribute_value_id',
        )->withTimestamps();
    }
}
