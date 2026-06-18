<?php

namespace App\Models;

use App\Enums\DealStatus;
use App\Enums\StayPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The pursuit record. Starts when someone expresses interest and ends when
 * they become a tenant or walk away. Pipeline stage is stored on `status`.
 *
 * offer_sent and offer_viewed are cached here for simple pipeline reporting,
 * even though the linked Offer record also tracks send/view events.
 *
 * Expected values live on Deal. Actual values live on Lease.
 *
 * @property int              $id
 * @property int              $contact_id
 * @property DealStatus       $status
 * @property string           $expected_value  NUMERIC(10,2)
 * @property string|null      $expected_move_in Y-m-d
 * @property int|null         $expected_stay_length
 * @property StayPeriod|null  $expected_stay_period
 * @property string|null      $storage_reason
 * @property string|null      $desired_size  NUMERIC(8,2)
 * @property int|null         $desired_unit_class_id
 * @property string|null      $intent_notes
 * @property Carbon           $created_at
 * @property Carbon           $updated_at
 *
 * @property-read Contact                      $contact
 * @property-read UnitClass|null               $desiredUnitClass
 * @property-read Collection<int, Offer>       $offers
 * @property-read Collection<int, Lease>       $leases
 * @property-read Collection<int, Task>        $tasks
 * @property-read Collection<int, Comment>     $comments
 */
class Deal extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'status',
        'expected_value',
        'expected_move_in',
        'expected_stay_length',
        'expected_stay_period',
        'storage_reason',
        'desired_size',
        'desired_unit_class_id',
        'intent_notes',
    ];

    protected function casts(): array
    {
        return [
            'status'               => DealStatus::class,
            'expected_value'       => 'decimal:2',
            'expected_move_in'     => 'date',
            'expected_stay_period' => StayPeriod::class,
            'desired_size'         => 'decimal:2',
        ];
    }

    public function isActivePursuit(): bool
    {
        return $this->status->isActivePursuit();
    }

    /** @return BelongsTo<Contact, Deal> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<UnitClass, Deal> */
    public function desiredUnitClass(): BelongsTo
    {
        return $this->belongsTo(UnitClass::class, 'desired_unit_class_id');
    }

    /** @return HasMany<Offer> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<Lease> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /** @return MorphMany<Task> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** @return MorphMany<Comment> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
