<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EmailTemplate extends Model
{
    protected $fillable = [
        'name',
    ];

    public function emailBlocks(): HasMany
    {
        return $this->hasMany(EmailBlock::class)->orderBy('order');
    }
}
