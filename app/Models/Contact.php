<?php

namespace App\Models;

use App\Models\Concerns\HasNotes;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
use App\Enums\ContactSource;
use App\Enums\DealStatus;
use App\Enums\ContractStatus;
use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Durable identity record for prospective and current renters.
 * Not all contacts become tenants. Contacts do not authenticate —
 * they interact via shareable offer links (token-based, no login).
 *
 * @property int                       $id
 * @property string                    $first_name
 * @property string                    $last_name
 * @property string|null               $email
 * @property string|null               $company
 * @property ContactLifecycleStatus    $status
 * @property ContactRecordStatus       $contact_status
 * @property int|null                  $canonical_contact_id
 * @property ContactSource|null        $source
 * @property string|null               $source_detail
 * @property int|null                  $assigned_to
 * @property Carbon|null               $last_contacted_at
 * @property int|null                  $created_by
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 *
 * @property-read Contact|null                 $canonicalContact
 * @property-read Collection<int, Contact>     $duplicates
 * @property-read Employee|null                $assignee
 * @property-read Employee|null                $creator
 * @property-read Collection<int, ContactChannel> $channels
 * @property-read Collection<int, ContactAddress> $addresses
 * @property-read Collection<int, Deal>        $deals
 * @property-read Collection<int, Offer>       $offers
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, Contract>    $contracts
 * @property-read Collection<int, Task>        $tasks
 * @property-read Collection<int, Note>        $notes
 * @property-read Collection<int, PropertyValue> $propertyValues
 */
class Contact extends TenantModel
{
    use HasFactory, HasNotes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'company',
        'status',
        'contact_status',
        'canonical_contact_id',
        'source',
        'source_detail',
        'assigned_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'            => ContactLifecycleStatus::class,
            'contact_status'    => ContactRecordStatus::class,
            'source'            => ContactSource::class,
            'last_contacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('working', function (Builder $builder) {
            $builder->whereNotIn('contact_status', [
                ContactRecordStatus::Duplicate->value,
                ContactRecordStatus::Archived->value,
            ]);
        });
    }

    /**
     * Derive lifecycle status from linked Deals, Reservations, and Contracts.
     * Priority order (highest wins): tenant → opportunity → lead → past_tenant → lost → prospect.
     */
    public function deriveLifecycleStatus(): ContactLifecycleStatus
    {
        if ($this->contracts()->where('status', ContractStatus::Active->value)->exists()) {
            return ContactLifecycleStatus::Tenant;
        }

        if ($this->reservations()->whereIn('status', [
            ReservationStatus::Pending->value,
            ReservationStatus::Confirmed->value,
        ])->exists()) {
            return ContactLifecycleStatus::Opportunity;
        }

        if ($this->deals()->whereIn('status', DealStatus::activePursuitValues())->exists()) {
            return ContactLifecycleStatus::Lead;
        }

        if ($this->contracts()->where('status', ContractStatus::MovedOut->value)->exists()) {
            return ContactLifecycleStatus::PastTenant;
        }

        if (
            $this->deals()->exists()
            && ! $this->deals()->where('status', '!=', DealStatus::ClosedLost->value)->exists()
            && ! $this->reservations()->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::Confirmed->value,
            ])->exists()
            && ! $this->contracts()->where('status', ContractStatus::Active->value)->exists()
        ) {
            return ContactLifecycleStatus::Lost;
        }

        return ContactLifecycleStatus::Prospect;
    }

    /** Recalculate and persist the cached lifecycle status. */
    public function recalculateStatus(): ContactLifecycleStatus
    {
        $derived = $this->deriveLifecycleStatus();

        if ($this->status !== $derived) {
            $this->forceFill(['status' => $derived])->saveQuietly();
        }

        return $derived;
    }

    /** @return BelongsTo<Contact, Contact> */
    public function canonicalContact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_contact_id');
    }

    /** @return HasMany<Contact> */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_contact_id');
    }

    /** @return BelongsTo<Employee, Contact> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    /** @return BelongsTo<Employee, Contact> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** @return HasMany<ContactChannel> */
    public function channels(): HasMany
    {
        return $this->hasMany(ContactChannel::class);
    }

    /** @return HasMany<ContactAddress> */
    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

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

    /** @return HasMany<Contract, Contact> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return MorphMany<Task> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    /** @return MorphMany<PropertyValue> */
    public function propertyValues(): MorphMany
    {
        return $this->morphMany(PropertyValue::class, 'propertable');
    }
}
