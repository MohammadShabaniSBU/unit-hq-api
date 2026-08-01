<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Canonical communication record — body, delivery state, and provenance.
 *
 * @property int         $id
 * @property int         $message_thread_id
 * @property string      $direction
 * @property string      $status
 * @property string|null $body_text
 * @property string|null $body_html
 * @property string      $from_address
 * @property string      $to_address
 * @property string|null $provider
 * @property int|null    $communication_account_id
 * @property string|null $provider_message_id
 * @property array<string, mixed>|null $threading_evidence
 * @property string      $source
 * @property array<string, mixed>|null $source_ref
 * @property array<int, array<string, mixed>>|null $delivery_events
 * @property bool        $auto_generated
 * @property Carbon|null $sent_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read MessageThread                        $thread
 * @property-read CommunicationAccount|null            $communicationAccount
 * @property-read Collection<int, MessageAttachment>   $attachments
 * @property-read Interaction|null                     $interaction
 */
class Message extends Model
{
    protected $fillable = [
        'message_thread_id',
        'direction',
        'status',
        'body_text',
        'body_html',
        'from_address',
        'to_address',
        'provider',
        'communication_account_id',
        'provider_message_id',
        'threading_evidence',
        'source',
        'source_ref',
        'delivery_events',
        'auto_generated',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'status' => MessageStatus::class,
            'provider' => Provider::class,
            'source' => MessageSource::class,
            'threading_evidence' => 'array',
            'source_ref' => 'array',
            'delivery_events' => 'array',
            'auto_generated' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MessageThread, Message> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'message_thread_id');
    }

    /** @return BelongsTo<CommunicationAccount, Message> */
    public function communicationAccount(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class, 'communication_account_id');
    }

    /** @return HasMany<MessageAttachment> */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /** @return HasOne<Interaction> */
    public function interaction(): HasOne
    {
        return $this->hasOne(Interaction::class);
    }
}
