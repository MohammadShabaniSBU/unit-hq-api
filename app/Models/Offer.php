<?php

namespace App\Models;

use App\Models\Concerns\HasNotes;
use App\Support\Auth\Concerns\VisibleToEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Commercial proposal. Sits between Deal and Reservation in the pipeline.
 *
 * Status transitions: draft → sent → viewed → accepted.
 * Expiry is checked at READ TIME against expires_at — status is never flipped
 * by a background job, only by application events.
 *
 * The shareable link is built from token, not id. Token is a cryptographically
 * random URL-safe string generated at insert time.
 *
 * offers does NOT hold a back-reference to reservations. The FK is one-way:
 * reservations.offer_option_id → offer_options.id.
 *
 * @property int         $id
 * @property int         $deal_id
 * @property int         $contact_id
 * @property string      $token
 * @property string      $status       draft|sent|viewed|accepted|expired
 * @property Carbon      $expires_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $first_viewed_at
 * @property Carbon|null $accepted_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Deal                            $deal
 * @property-read Contact                         $contact
 * @property-read Collection<int, OfferOption>    $options
 * @property-read Collection<int, OfferDelivery>  $deliveries
 * @property-read Collection<int, Note>             $notes
 */
class Offer extends Model
{
    use HasFactory, HasNotes, VisibleToEmployee;

    /** @var array<int, string> */
    public const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'expired'];

    protected $fillable = [
        'deal_id',
        'contact_id',
        'token',
        'status',
        'expires_at',
        'sent_at',
        'first_viewed_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'       => 'datetime',
            'sent_at'          => 'datetime',
            'first_viewed_at'  => 'datetime',
            'accepted_at'      => 'datetime',
        ];
    }

    /**
     * Search by offer id, deal id, or linked contact name / email / company.
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
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
            });
        });
    }

    /**
     * Grouped count of offers per status, honoring the same search filter.
     * Returns every status key (including zero counts), in STATUSES order.
     *
     * @return array<string, int>
     */
    public static function statusCounts(?string $search = null, ?Builder $base = null): array
    {
        $raw = ($base ?? static::query())
            ->when($search, fn (Builder $q) => $q->search($search))
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->pluck('aggregate', 'status');

        $counts = collect($raw)->mapWithKeys(fn (mixed $count, mixed $status) => [
            (string) $status => (int) $count,
        ]);

        return collect(self::STATUSES)
            ->mapWithKeys(fn (string $status) => [
                $status => (int) ($counts[$status] ?? 0),
            ])
            ->all();
    }

    /**
     * Base query for a single board column: one status, optional search,
     * contact + options count, sorted by keyset order (updated_at DESC, id DESC).
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    public function scopeForBoardColumn(Builder $query, string $status, ?string $search = null): Builder
    {
        return $query
            ->where('status', $status)
            ->when($search, fn (Builder $q) => $q->search($search))
            ->with(['contact'])
            ->withCount('options')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /** @return BelongsTo<Deal, Offer> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<Contact, Offer> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return HasMany<OfferOption> */
    public function options(): HasMany
    {
        return $this->hasMany(OfferOption::class);
    }

    /** @return HasMany<OfferDelivery> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(OfferDelivery::class);
    }
}
