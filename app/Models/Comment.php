<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only comment log. Attachable to Contact, Deal, Task, and Reservation.
 * No updated_at — comments are never edited. Corrections are new comments.
 *
 * @property int    $id
 * @property string $commentable_type
 * @property int    $commentable_id
 * @property int    $employee_id
 * @property string $content
 * @property Carbon $created_at
 *
 * @property-read \Illuminate\Database\Eloquent\Model $commentable
 * @property-read Employee $employee
 */
class Comment extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'employee_id',
        'content',
    ];

    /** @return MorphTo */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Employee, Comment> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
