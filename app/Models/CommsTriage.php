<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Unmatched inbound parking lot — never creates contacts implicitly.
 *
 * @property int         $id
 * @property int         $communication_account_id
 * @property string      $provider
 * @property string      $provider_message_id
 * @property string      $channel
 * @property string      $sender_value
 * @property array<string, mixed> $preview
 * @property array<string, mixed> $payload
 * @property string      $status
 * @property int|null    $resolved_contact_id
 * @property int|null    $resolved_message_id
 * @property Carbon|null $resolved_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read CommunicationAccount $communicationAccount
 * @property-read Contact|null         $resolvedContact
 * @property-read Message|null         $resolvedMessage
 */
class CommsTriage extends Model
{
    protected $table = 'comms_triage';

    protected $fillable = [
        'communication_account_id',
        'provider',
        'provider_message_id',
        'channel',
        'sender_value',
        'preview',
        'payload',
        'status',
        'resolved_contact_id',
        'resolved_message_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'channel' => Channel::class,
            'preview' => 'array',
            'payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CommunicationAccount, CommsTriage> */
    public function communicationAccount(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class);
    }

    /** @return BelongsTo<Contact, CommsTriage> */
    public function resolvedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'resolved_contact_id');
    }

    /** @return BelongsTo<Message, CommsTriage> */
    public function resolvedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'resolved_message_id');
    }
}
