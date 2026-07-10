<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\HasNotes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Model;

/**
 * Inventory hold record. Always references a specific unit — never a class.
 * Created in a single transaction with OfferOption.selected_at and Offer.status.
 *
 * Unit availability is derived from active contracts + non-expired reservations.
 * is_available is never stored as a column on Unit.
 *
 * @property int                $id
 * @property int                $unit_id
 * @property int                $contact_id
 * @property int|null           $price_id
 * @property int|null           $deal_id
 * @property ReservationStatus  $status
 * @property int|null           $offer_option_id
 * @property Carbon             $expires_at
 * @property Carbon             $created_at
 * @property Carbon             $updated_at
 *
 * @property-read Unit              $unit
 * @property-read Contact           $contact
 * @property-read Price|null        $price
 * @property-read Deal|null         $deal
 * @property-read OfferOption|null  $offerOption
 * @property-read Contract|null     $contract
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Note> $notes
 */
class Reservation extends Model
{
    use HasFactory, HasNotes;

    protected $fillable = [
        'unit_id',
        'contact_id',
        'price_id',
        'deal_id',
        'offer_option_id',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status'     => ReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Unit, Reservation> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Contact, Reservation> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Price, Reservation> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /** @return BelongsTo<Deal, Reservation> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<OfferOption, Reservation> */
    public function offerOption(): BelongsTo
    {
        return $this->belongsTo(OfferOption::class);
    }

    /** @return HasOne<Contract, Reservation> */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }
}
