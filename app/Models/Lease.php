<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Operational and billing anchor created at contract signing.
 * Every charge, payment, and allocation references a lease.
 *
 * Actual values live on Lease. Expected values live on Deal.
 * Derived fields (duration, size variance) are computed at query time.
 *
 * reservation_id is nullable for walk-in leases that bypass the offer pipeline.
 *
 * @property int         $id
 * @property int         $unit_id
 * @property int         $contact_id
 * @property int|null    $reservation_id
 * @property int|null    $deal_id
 * @property string      $start_date     Y-m-d
 * @property string|null $end_date       Y-m-d
 * @property string      $actual_rate    NUMERIC(10,2)
 * @property string|null $actual_insurance NUMERIC(10,2)
 * @property LeaseStatus $status         active|moved_out|terminated|expired
 * @property Carbon      $signed_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Unit                         $unit
 * @property-read Contact                      $contact
 * @property-read Reservation|null             $reservation
 * @property-read Deal|null                    $deal
 * @property-read Collection<int, Invoice>     $invoices
 * @property-read Collection<int, Charge>      $charges
 * @property-read Collection<int, Payment>     $payments
 */
class Lease extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'contact_id',
        'reservation_id',
        'deal_id',
        'start_date',
        'end_date',
        'actual_rate',
        'actual_insurance',
        'status',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => LeaseStatus::class,
            'start_date'       => 'date',
            'end_date'         => 'date',
            'actual_rate'      => 'decimal:2',
            'actual_insurance' => 'decimal:2',
            'signed_at'        => 'datetime',
        ];
    }

    /** @return BelongsTo<Unit, Lease> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Contact, Lease> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Reservation, Lease> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Deal, Lease> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return HasMany<Invoice> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Charge> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Payment> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
