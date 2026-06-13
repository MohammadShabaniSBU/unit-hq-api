<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The pursuit record. Holds pipeline stage, forecast, and intent.
 * Expected values live on Deal. Actual values live on Lease.
 *
 * @property int         $id
 * @property int         $contact_id
 * @property string      $pipeline_stage
 * @property string      $expected_value  NUMERIC(10,2)
 * @property string|null $expected_move_in Y-m-d
 * @property string|null $intent_notes
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Contact                      $contact
 * @property-read Collection<int, Offer>       $offers
 * @property-read Collection<int, Lease>       $leases
 * @property-read Collection<int, Task>        $tasks
 * @property-read Collection<int, Comment>     $comments
 */
class Deal extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'contact_id',
        'pipeline_stage',
        'expected_value',
        'expected_move_in',
        'intent_notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_value'    => 'decimal:2',
            'expected_move_in'  => 'date',
        ];
    }

    /** @return BelongsTo<Contact, Deal> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
