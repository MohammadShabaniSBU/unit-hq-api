<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Click-to-dial intent. The call message arrives later via webhook correlation —
 * dial never synthesizes a Message (no-optimism).
 *
 * @property int         $id
 * @property int         $employee_id
 * @property int         $contact_id
 * @property string      $to_number
 * @property string|null $context_type
 * @property int|null    $context_id
 * @property string|null $aircall_call_id
 * @property int|null    $message_id
 * @property string      $status  requested|dial_failed|correlated|uncorrelated
 * @property string|null $error
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Employee $employee
 * @property-read Contact  $contact
 * @property-read Message|null $message
 */
class CallIntent extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_DIAL_FAILED = 'dial_failed';

    public const STATUS_CORRELATED = 'correlated';

    public const STATUS_UNCORRELATED = 'uncorrelated';

    protected $fillable = [
        'employee_id',
        'contact_id',
        'to_number',
        'context_type',
        'context_id',
        'aircall_call_id',
        'message_id',
        'status',
        'error',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
