<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\HasAutomationTriggers;
use App\Support\Billing\CurrencyGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Append-only credit entry. Stripe rails write via webhook + idempotency_key;
 * manual rails (cash / bank_transfer / card_external) via authenticated employee.
 *
 * Reversals are made by inserting a new payment with reversal_of_payment_id.
 * Unallocated amount (payment.amount - SUM allocations) is credit on the contract.
 *
 * @property int                $id
 * @property int                $contract_id
 * @property string             $amount                NUMERIC(10,2)
 * @property string             $currency              ISO 4217 snapshot from contract
 * @property PaymentMethod|null $method
 * @property Carbon|null        $received_on
 * @property string|null        $reference
 * @property string|null        $stripe_payment_intent_id
 * @property string             $idempotency_key
 * @property int|null           $reversal_of_payment_id
 * @property Carbon             $created_at
 *
 * @property-read Contract                    $contract
 * @property-read Payment|null                $reversalOf
 * @property-read Collection<int, Allocation> $allocations
 * @property-read StripeWebhookEvent|null     $webhookEvent
 */
class Payment extends Model
{
    use HasFactory;
    use HasAutomationTriggers;
    use \App\Support\Auth\Concerns\VisibleToEmployee;

    const UPDATED_AT = null;

    /**
     * Payments are append-only — an update trigger on an immutable table is a lie.
     *
     * @return list<'created'|'updated'|'deleted'>
     */
    public static function automationTriggerLifecycles(): array
    {
        return ['created'];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if ($payment->currency === null || $payment->currency === '') {
                $payment->currency = $payment->contract()->value('currency') ?? 'EUR';
            }

            $contract = $payment->relationLoaded('contract')
                ? $payment->contract
                : Contract::query()->find($payment->contract_id);

            if ($contract !== null) {
                CurrencyGuard::assertMatchesContract($contract, (string) $payment->currency);
            }
        });
    }

    protected $fillable = [
        'contract_id',
        'amount',
        'currency',
        'method',
        'received_on',
        'reference',
        'stripe_payment_intent_id',
        'idempotency_key',
        'reversal_of_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'received_on' => 'date',
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
