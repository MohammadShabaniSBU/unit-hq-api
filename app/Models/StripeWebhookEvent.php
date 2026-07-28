<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw Stripe webhook event stored for reconciliation.
 * The ledger is the system of record — events are inputs reconciled against it.
 *
 * Per-site direct charges (no Connect): each site holds its own Stripe keys
 * (`site_stripe_settings`). The inbound webhook URL carries a per-site
 * `webhook_route_token`; that site's `webhook_secret` verifies the
 * `Stripe-Signature` header. New rows always set site_id; legacy rows
 * (from before per-site routing) may be null.
 *
 * @property int         $id
 * @property int|null    $site_id
 * @property string      $stripe_event_id
 * @property string      $event_type
 * @property array       $payload
 * @property string      $processing_status pending|processed|failed
 * @property int|null    $payment_id
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 *
 * @property-read Site|null    $site
 * @property-read Payment|null $payment
 */
class StripeWebhookEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'stripe_event_id',
        'event_type',
        'payload',
        'processing_status',
        'payment_id',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'received_at'  => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, StripeWebhookEvent> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Site, StripeWebhookEvent> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
