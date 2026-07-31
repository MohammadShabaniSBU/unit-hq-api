<?php

namespace App\Models;

use App\Support\Billing\CurrencyGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Maps a payment to a charge for a specific amount. Append-only.
 * A charge is fully paid when SUM(allocations.amount) = charge.amount.
 * Unallocated payment amount is credit on the contract.
 *
 * @property int    $id
 * @property int    $payment_id
 * @property int    $charge_id
 * @property string $amount     NUMERIC(10,2)
 * @property Carbon $created_at
 *
 * @property-read Payment $payment
 * @property-read Charge  $charge
 */
class Allocation extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (Allocation $allocation): void {
            $charge = $allocation->relationLoaded('charge')
                ? $allocation->charge
                : Charge::query()->find($allocation->charge_id);
            $payment = $allocation->relationLoaded('payment')
                ? $allocation->payment
                : Payment::query()->find($allocation->payment_id);

            if ($charge !== null && $payment !== null) {
                CurrencyGuard::assertAllocatable($charge, $payment);
            }
        });
    }

    protected $fillable = [
        'payment_id',
        'charge_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Payment, Allocation> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Charge, Allocation> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
