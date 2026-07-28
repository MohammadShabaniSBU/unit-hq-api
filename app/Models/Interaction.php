<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\InteractionCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unified CRM timeline entry for contact activity (call, email, SMS, WhatsApp, …).
 *
 * @property int         $id
 * @property int         $contact_id
 * @property int|null    $deal_id
 * @property string      $channel
 * @property string      $direction
 * @property Carbon      $occurred_at
 * @property string|null $content
 * @property string|null $summary
 * @property array<string, mixed>|null $metadata
 * @property string|null $message_id  provider-assigned message id (delivery reconciliation)
 * @property int|null    $account_id  CommunicationAccount used to send this
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Contact  $contact
 * @property-read Deal|null $deal
 * @property-read CommunicationAccount|null $account
 */
class Interaction extends Model
{
    public const CHANNELS = [
        'email',
        'sms',
        'whatsapp',
        'call',
        'other',
    ];

    public const DIRECTIONS = [
        'inbound',
        'outbound',
    ];

    protected $fillable = [
        'contact_id',
        'deal_id',
        'channel',
        'direction',
        'occurred_at',
        'content',
        'summary',
        'metadata',
        'message_id',
        'account_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $interaction): void {
            $dispatch = static function () use ($interaction): void {
                event(new InteractionCreated($interaction));
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($dispatch);

                return;
            }

            $dispatch();
        });
    }

    /** @return BelongsTo<Contact, Interaction> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Deal, Interaction> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<CommunicationAccount, Interaction> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class, 'account_id');
    }
}
