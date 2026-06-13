<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Durable identity record for prospective and current renters.
 * Not all contacts become tenants. Contacts do not authenticate —
 * they interact via shareable offer links (token-based, no login).
 *
 * @property int         $id
 * @property string      $first_name
 * @property string      $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read Collection<int, Deal>        $deals
 * @property-read Collection<int, Offer>       $offers
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, Lease>       $leases
 * @property-read Collection<int, Task>        $tasks
 * @property-read Collection<int, Comment>     $comments
 * @property-read Collection<int, PropertyValue> $propertyValues
 */
class Contact extends TenantModel
{
    use HasFactory;

    protected array $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
    ];

    /** @return HasMany<Deal> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** @return HasMany<Offer> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<Reservation> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
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

    /** @return MorphMany<PropertyValue> */
    public function propertyValues(): MorphMany
    {
        return $this->morphMany(PropertyValue::class, 'propertable');
    }
}
