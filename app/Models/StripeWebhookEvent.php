<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw Stripe webhook event stored for reconciliation.
 * The ledger is the system of record — events are inputs reconciled against it.
 *
 * Routing: platform receives the webhook, reads account from the Stripe event,
 * looks up tenants by stripe_connect_account_id, routes to that tenant's DB.
 *
 * @property int         $id
 * @property string      $stripe_event_id
 * @property string      $event_type
 * @property array       $payload
 * @property string      $processing_status pending|processed|failed
 * @property int|null    $payment_id
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 *
 * @property-read Payment|null $payment
 */
class StripeWebhookEvent extends TenantModel
{
    use HasFactory;

    public $timestamps = false;

    protected array $fillable = [
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
}
