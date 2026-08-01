<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw Stripe webhook event stored for reconciliation.
 * The ledger is the system of record — events are inputs reconciled against it.
 *
 * Idempotency is per payment_provider_account (two accounts may emit the same
 * stripe_event_id). The inbound URL carries account_token; that account's
 * webhook_secret verifies Stripe-Signature.
 *
 * @property int         $id
 * @property int|null    $payment_provider_account_id
 * @property string      $stripe_event_id
 * @property string      $event_type
 * @property array       $payload
 * @property string      $processing_status pending|processed|failed
 * @property int|null    $payment_id
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 *
 * @property-read PaymentProviderAccount|null $paymentProviderAccount
 * @property-read Payment|null                $payment
 */
class StripeWebhookEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'payment_provider_account_id',
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
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<PaymentProviderAccount, $this> */
    public function paymentProviderAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccount::class);
    }
}
