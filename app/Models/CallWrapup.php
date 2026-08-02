<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Operator annotation on a call message — disposition + optional note.
 * One row per message; edits update in place (not ledger-append).
 *
 * @property int         $id
 * @property int         $message_id
 * @property string|null $disposition
 * @property string|null $note
 * @property int         $employee_id
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Message  $message
 * @property-read Employee $employee
 */
class CallWrapup extends Model
{
    protected $fillable = [
        'message_id',
        'disposition',
        'note',
        'employee_id',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
