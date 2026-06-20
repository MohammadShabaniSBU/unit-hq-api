<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only note log. Attachable to Contact, Deal, Offer, Contract, and Reservation.
 * No updated_at — notes are never edited. Corrections are new notes.
 *
 * @property int    $id
 * @property string $notable_type
 * @property int    $notable_id
 * @property int    $employee_id
 * @property string $content
 * @property Carbon $created_at
 *
 * @property-read \Illuminate\Database\Eloquent\Model $notable
 * @property-read Employee $employee
 */
class Note extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'notable_type',
        'notable_id',
        'employee_id',
        'content',
    ];

    /** @return MorphTo */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Employee, Note> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
