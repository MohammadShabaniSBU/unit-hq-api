<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Operational and billing anchor created at contract signing.
 * Every charge, payment, and allocation references a contract.
 *
 * Line items (unit, insurance, etc.) live on ContractItem as a polymorphic
 * collection rather than flat FK columns. Each item carries its own rate.
 *
 * reservation_id is nullable for walk-in contracts that bypass the pipeline.
 *
 * @property int            $id
 * @property int            $contact_id
 * @property int|null       $reservation_id
 * @property int|null       $deal_id
 * @property string         $start_date     Y-m-d
 * @property string|null    $end_date       Y-m-d
 * @property ContractStatus $status         active|moved_out|terminated|expired
 * @property Carbon         $signed_at
 * @property Carbon         $created_at
 * @property Carbon         $updated_at
 *
 * @property-read Contact                          $contact
 * @property-read Reservation|null                 $reservation
 * @property-read Deal|null                        $deal
 * @property-read Collection<int, ContractItem>    $items
 * @property-read ContractItem|null                $unitItem
 * @property-read ContractItem|null                $insuranceItem
 * @property-read Collection<int, Invoice>         $invoices
 * @property-read Collection<int, Charge>          $charges
 * @property-read Collection<int, Payment>         $payments
 */
class Contract extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'reservation_id',
        'deal_id',
        'start_date',
        'end_date',
        'status',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'     => ContractStatus::class,
            'start_date' => 'date',
            'end_date'   => 'date',
            'signed_at'  => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, Contract> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Reservation, Contract> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Deal, Contract> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return HasMany<ContractItem, Contract> */
    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    /** @return HasOne<ContractItem, Contract> */
    public function unitItem(): HasOne
    {
        return $this->hasOne(ContractItem::class)->where('item_type', 'unit');
    }

    /** @return HasOne<ContractItem, Contract> */
    public function insuranceItem(): HasOne
    {
        return $this->hasOne(ContractItem::class)->where('item_type', 'insurance');
    }

    /** @return HasMany<Invoice, Contract> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Charge, Contract> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Payment, Contract> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
