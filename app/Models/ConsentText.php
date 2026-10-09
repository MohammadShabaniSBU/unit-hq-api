<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsentTextFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable wording shown when consent was collected. A new version is a new row.
 *
 * @property int $id
 * @property string $key
 * @property int $version
 * @property string $locale
 * @property string $body
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class ConsentText extends Model
{
    /** @use HasFactory<ConsentTextFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'key',
        'version',
        'locale',
        'body',
        'created_by',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
