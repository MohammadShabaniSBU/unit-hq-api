<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutopayAttemptStatus;
use App\Enums\AutopayAttemptTrigger;
use App\Models\Concerns\HasAutomationTriggers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Off-session collection attempt. Created by S06-04's collector; resolved by
 * S06-03's webhook (success → ledger; failure → codes for S07 dunning).
 *
 * @property int                    $id
 * @property int                    $contract_id
 * @property int                    $payment_method_id
 * @property list<int>              $charge_ids
 * @property string                 $amount
 * @property string                 $currency
 * @property string|null            $stripe_payment_intent_id
 * @property AutopayAttemptStatus   $status
 * @property string|null            $failure_code
 * @property string|null            $decline_code
 * @property string|null            $failure_message
 * @property AutopayAttemptTrigger  $triggered_by
 * @property int|null               $billing_run_id
 * @property Carbon                 $attempted_at
 * @property Carbon|null            $resolved_at
 * @property Carbon|null            $created_at
 *
 * @property-read Contract       $contract
 * @property-read PaymentMethod  $paymentMethod
 * @property-read BillingRun|null $billingRun
 */
class AutopayAttempt extends Model
{
    use HasFactory;
    use HasAutomationTriggers;

    public const UPDATED_AT = null;

    protected $fillable = [
        'contract_id',
        'payment_method_id',
        'charge_ids',
        'amount',
        'currency',
        'stripe_payment_intent_id',
        'status',
        'failure_code',
        'decline_code',
        'failure_message',
        'triggered_by',
        'billing_run_id',
        'attempted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'charge_ids' => 'array',
            'amount' => 'decimal:2',
            'status' => AutopayAttemptStatus::class,
            'triggered_by' => AutopayAttemptTrigger::class,
            'attempted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<BillingRun, $this> */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }
}
