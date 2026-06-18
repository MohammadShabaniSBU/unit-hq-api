<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Inventory hold record. Always references a specific unit — never a class.
 * Created in a single transaction with OfferOption.selected_at and Offer.status.
 *
 * Unit availability is derived from active leases + non-expired reservations.
 * is_available is never stored as a column on Unit.
 *
 * @property int         $id
 * @property int         $unit_id
 * @property int                $contact_id
 * @property ReservationStatus  $status
 * @property int                $offer_option_id
 * @property Carbon      $expires_at
 * @property string|null $hold_notes
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Unit              $unit
 * @property-read Contact           $contact
 * @property-read OfferOption       $offerOption
 * @property-read Lease|null        $lease
 * @property-read MorphMany<Comment> $comments
 */
class Reservation extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'unit_id',
        'contact_id',
        'offer_option_id',
        'expires_at',
        'hold_notes',
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

    /** @return BelongsTo<OfferOption, Reservation> */
    public function offerOption(): BelongsTo
    {
        return $this->belongsTo(OfferOption::class);
    }

    /** @return HasOne<Lease> */
    public function lease(): HasOne
    {
        return $this->hasOne(Lease::class);
    }

    /** @return MorphMany<Comment> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
