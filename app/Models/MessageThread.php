<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Canonical conversation thread for a contact on one channel.
 *
 * @property int         $id
 * @property int         $contact_id
 * @property string      $channel
 * @property string|null $subject
 * @property string|null $channel_key
 * @property Carbon      $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property int|null    $assigned_employee_id
 * @property int         $unread_count
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Contact                   $contact
 * @property-read Employee|null             $assignee
 * @property-read Collection<int, Message>  $messages
 */
class MessageThread extends Model
{
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    protected $fillable = [
        'contact_id',
        'channel',
        'subject',
        'channel_key',
        'last_message_at',
        'last_inbound_at',
        'assigned_employee_id',
        'unread_count',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Contact, MessageThread> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Employee, MessageThread> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    /** @return HasMany<Message> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
