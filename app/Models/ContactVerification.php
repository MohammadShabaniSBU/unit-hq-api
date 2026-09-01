<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conversation-scoped OTP challenge. Append-only except attempts / consumed_at.
 *
 * @property int $id
 * @property int $contact_id
 * @property int|null $agent_conversation_id
 * @property int $contact_channel_id
 * @property int|null $site_id
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 * @property-read Contact $contact
 * @property-read AgentConversation|null $conversation
 * @property-read ContactChannel $channel
 * @property-read Site|null $site
 */
class ContactVerification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'contact_id',
        'agent_conversation_id',
        'contact_channel_id',
        'site_id',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, ContactVerification> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<AgentConversation, ContactVerification> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<ContactChannel, ContactVerification> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContactChannel::class, 'contact_channel_id');
    }

    /** @return BelongsTo<Site, ContactVerification> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
