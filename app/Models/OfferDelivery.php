<?php

namespace App\Models;

use App\Enums\LogChannel;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per send event. An offer can be resent across multiple channels
 * or to a corrected address without touching the offer record.
 *
 * @property int         $id
 * @property int         $offer_id
 * @property string      $channel          email|sms|whatsapp
 * @property string      $recipient_address
 * @property Carbon      $sent_at
 * @property Carbon|null $delivered_at
 * @property string      $delivery_status  queued|sent|delivered|failed
 * @property string|null $message_id       provider-assigned message id (delivery reconciliation)
 * @property int|null    $account_id       CommunicationAccount used to send this
 * @property Carbon      $created_at
 *
 * @property-read Offer $offer
 * @property-read CommunicationAccount|null $account
 */
class OfferDelivery extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'offer_id',
        'channel',
        'recipient_address',
        'sent_at',
        'delivered_at',
        'delivery_status',
        'message_id',
        'account_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'      => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Log offer.sent once on create. Status flaps (queued→sent→delivered) stay tier-1 only.
        static::created(function (self $delivery): void {
            $delivery->loadMissing('offer');
            if ($delivery->offer === null) {
                return;
            }

            RecordsActivity::log(LogChannel::Comms, 'offer.sent', $delivery->offer, [
                'channel' => $delivery->channel,
                'delivery_id' => $delivery->id,
            ]);
        });
    }

    /** @return BelongsTo<Offer, OfferDelivery> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** @return BelongsTo<CommunicationAccount, OfferDelivery> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CommunicationAccount::class, 'account_id');
    }
}
