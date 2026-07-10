<?php

namespace App\Models;

use App\Models\Concerns\HasNotes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

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
    use HasFactory, HasNotes;

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
