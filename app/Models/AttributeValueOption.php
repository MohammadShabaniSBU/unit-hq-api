<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Junction row for multiselect attribute values.
 *
 * @property int    $id
 * @property int    $attribute_value_id
 * @property int    $attribute_option_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read AttributeValue  $value
 * @property-read AttributeOption $option
 */
class AttributeValueOption extends Model
{
    use HasFactory;

    protected $table = 'attribute_value_options';

    protected $fillable = [
        'attribute_value_id',
        'attribute_option_id',
    ];

    /** @return BelongsTo<AttributeValue, $this> */
    public function value(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }

    /** @return BelongsTo<AttributeOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'attribute_option_id');
    }
}
