<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Append-only credit entry. Confirmed from Stripe webhooks using
 * idempotency_key — never optimistically from the client.
 *
 * Reversals are made by inserting a new payment with reversal_of_payment_id.
 * Unallocated amount (payment.amount - SUM allocations) is credit on the contract.
 *
 * @property int         $id
 * @property int         $contract_id
 * @property string      $amount                NUMERIC(10,2)
 * @property string|null $stripe_payment_intent_id
 * @property string      $idempotency_key
 * @property int|null    $reversal_of_payment_id
 * @property Carbon      $created_at
 *
 * @property-read Contract                    $contract
 * @property-read Payment|null                $reversalOf
 * @property-read Collection<int, Allocation> $allocations
 * @property-read StripeWebhookEvent|null     $webhookEvent
 */
class Payment extends TenantModel
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'contract_id',
        'amount',
        'stripe_payment_intent_id',
        'idempotency_key',
        'reversal_of_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Contract, Payment> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Payment, Payment> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'reversal_of_payment_id');
    }

    /** @return HasMany<Allocation, Payment> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /** @return HasOne<StripeWebhookEvent, Payment> */
    public function webhookEvent(): HasOne
    {
        return $this->hasOne(StripeWebhookEvent::class);
    }
}
