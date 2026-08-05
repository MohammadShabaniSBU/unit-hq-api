<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Maps a Keevaris employee to an Aircall user for click-to-dial.
 *
 * @property int    $id
 * @property int    $employee_id
 * @property string $aircall_user_id
 * @property string $aircall_user_label
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Employee $employee
 */
class AircallUserLink extends Model
{
    protected $fillable = [
        'employee_id',
        'aircall_user_id',
        'aircall_user_label',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
