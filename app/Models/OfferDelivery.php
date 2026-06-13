<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property Carbon      $created_at
 *
 * @property-read Offer $offer
 */
class OfferDelivery extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected array $fillable = [
        'offer_id',
        'channel',
        'recipient_address',
        'sent_at',
        'delivered_at',
        'delivery_status',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'      => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Offer, OfferDelivery> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
