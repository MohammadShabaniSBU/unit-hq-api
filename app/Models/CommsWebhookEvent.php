<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw provider delivery webhook event stored for idempotent reconciliation.
 * Unique on (communication_account_id, provider_event_id) — Stripe-shaped.
 *
 * @property int         $id
 * @property int         $communication_account_id
 * @property string      $provider_event_id
 * @property array       $payload
 * @property string      $processing_status pending|processed|unmatched|failed
 * @property int|null    $message_id
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 *
 * @property-read CommunicationAccount $communicationAccount
 * @property-read Message|null         $message
 */
class CommsWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'communication_account_id',
        'provider_event_id',
        'payload',
        'processing_status',
        'message_id',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CommunicationAccount, $this> */
    public function communicationAccount(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
