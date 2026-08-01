<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tokenised "request payment" link targeting a set of open charges.
 * amount is a snapshot at create; open balance stays computed (invariant 5).
 * Expiry is read-time (invariant 13). Ledger writes happen only via S06-03 webhook.
 *
 * @property int                   $id
 * @property string                $token
 * @property int                   $contract_id
 * @property int                   $payment_provider_account_id
 * @property list<int>             $charge_ids
 * @property string                $amount
 * @property string                $currency
 * @property PaymentRequestStatus  $status
 * @property Carbon                $expires_at
 * @property string|null           $stripe_payment_intent_id
 * @property bool                  $save_card_requested
 * @property int|null              $paid_payment_id
 * @property int|null              $created_by
 * @property Carbon                $created_at
 * @property Carbon                $updated_at
 *
 * @property-read Contract                $contract
 * @property-read PaymentProviderAccount  $paymentProviderAccount
 * @property-read Payment|null            $paidPayment
 * @property-read Employee|null           $creator
 */
class PaymentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'contract_id',
        'payment_provider_account_id',
        'charge_ids',
        'amount',
        'currency',
        'status',
        'expires_at',
        'stripe_payment_intent_id',
        'save_card_requested',
        'paid_payment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'charge_ids' => 'array',
            'amount' => 'decimal:2',
            'status' => PaymentRequestStatus::class,
            'expires_at' => 'datetime',
            'save_card_requested' => 'boolean',
        ];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<PaymentProviderAccount, $this> */
    public function paymentProviderAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccount::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function paidPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'paid_payment_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * Read-time expiry (invariant 13): column status stays pending until cancelled/paid.
     */
    public function isExpired(): bool
    {
        return $this->status === PaymentRequestStatus::Pending
            && $this->expires_at->isPast();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            PaymentRequestStatus::Paid,
            PaymentRequestStatus::Cancelled,
        ], true) || $this->isExpired();
    }

    /**
     * Public path segment — panel prefixes origin.
     */
    public function publicPath(): string
    {
        return '/pay/'.$this->token;
    }

    /**
     * @return Collection<int, Charge>
     */
    public function targetedCharges(): Collection
    {
        $ids = array_map('intval', $this->charge_ids ?? []);

        if ($ids === []) {
            return collect();
        }

        return Charge::query()
            ->with('allocations')
            ->where('contract_id', $this->contract_id)
            ->whereIn('id', $ids)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Current open total of the targeted charge set (bcmath).
     */
    public function currentOpenTotal(): string
    {
        $total = '0.00';

        foreach ($this->targetedCharges() as $charge) {
            $open = $charge->openAmount();
            if (bccomp($open, '0', 2) > 0) {
                $total = bcadd($total, $open, 2);
            }
        }

        return $total;
    }

    /**
     * True when the open set no longer matches the amount snapshot.
     */
    public function hasAmountMismatch(): bool
    {
        return bccomp($this->currentOpenTotal(), (string) $this->amount, 2) !== 0;
    }
}
