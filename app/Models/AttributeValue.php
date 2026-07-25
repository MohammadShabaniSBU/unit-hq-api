<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Typed EAV value for one attribute definition on one entity instance.
 * Exactly one scalar value_* column is populated for non-multiselect types;
 * multiselect selections live in attribute_value_options.
 *
 * @property int         $id
 * @property int         $definition_id
 * @property int         $entity_id
 * @property string|null $value_text
 * @property string|null $value_number NUMERIC(18,4) as string
 * @property string|null $value_date   Y-m-d
 * @property bool|null   $value_boolean
 * @property int|null    $value_option_id
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read AttributeDefinition          $definition
 * @property-read AttributeOption|null         $option
 * @property-read Collection<int, AttributeOption> $options
 */
class AttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'definition_id',
        'entity_id',
        'value_text',
        'value_number',
        'value_date',
        'value_boolean',
        'value_option_id',
    ];

    protected function casts(): array
    {
        return [
            'entity_id'      => 'integer',
            'value_number'   => 'decimal:4',
            'value_date'     => 'date',
            'value_boolean'  => 'boolean',
            'value_option_id'=> 'integer',
        ];
    }

    /** @return BelongsTo<AttributeDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'definition_id');
    }

    /** @return BelongsTo<AttributeOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'value_option_id');
    }

    /** @return BelongsToMany<AttributeOption, $this> */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeOption::class,
            'attribute_value_options',
            'attribute_value_id',
            'attribute_option_id',
        )->withTimestamps();
    }
}
