<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\HasNotes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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

    /**
     * Search by reservation id, deal id, contact name/email, or unit number.
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return $query->where(function (Builder $q) use ($term, $digits) {
            if ($digits !== '') {
                $q->where('id', $digits)
                    ->orWhere('deal_id', $digits);
            }

            $q->orWhereHas('contact', function (Builder $contactQuery) use ($term) {
                $contactQuery->where(function (Builder $inner) use ($term) {
                    $inner->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('company', 'like', "%{$term}%");
                });
            })->orWhereHas('unit', function (Builder $unitQuery) use ($term) {
                $unitQuery->where('unit_number', 'like', "%{$term}%");
            });
        });
    }

    /**
     * Grouped count of reservations per status, honoring the same search filter.
     * Returns every status key (including zero counts), in enum order.
     *
     * @return array<string, int>
     */
    public static function statusCounts(?string $search = null): array
    {
        $raw = static::query()
            ->when($search, fn (Builder $q) => $q->search($search))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status');

        $counts = collect($raw)->mapWithKeys(function (mixed $count, mixed $status) {
            $key = $status instanceof ReservationStatus
                ? $status->value
                : (string) $status;

            return [$key => (int) $count];
        });

        return collect(ReservationStatus::cases())
            ->mapWithKeys(fn (ReservationStatus $case) => [
                $case->value => (int) ($counts[$case->value] ?? 0),
            ])
            ->all();
    }

    /**
     * Base query for a single board column: one status, optional search,
     * contact + unit.site, sorted by keyset order (updated_at DESC, id DESC).
     *
     * @param  Builder<Reservation>  $query
     * @return Builder<Reservation>
     */
    public function scopeForBoardColumn(Builder $query, ReservationStatus $status, ?string $search = null): Builder
    {
        return $query
            ->where('status', $status->value)
            ->when($search, fn (Builder $q) => $q->search($search))
            ->with(['contact', 'unit.site'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
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
