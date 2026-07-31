<?php

namespace App\Models;

use App\Enums\ContactLifecycleStatus;
use App\Enums\ContactRecordStatus;
use App\Enums\ContactSource;
use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Enums\ReservationStatus;
use App\Models\Concerns\HasNotes;
use App\Models\Concerns\HasAutomationTriggers;
use App\Models\Concerns\LogsDirtyActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

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
 * @property-read Collection<int, Interaction> $interactions
 */
class Contact extends Model
{
    use HasFactory, HasNotes, HasAutomationTriggers, LogsDirtyActivity;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Crm;
    }

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
     * Search by first name, last name, email, or company.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('company', 'like', "%{$term}%");
        });
    }

    /**
     * Grouped count of contacts per lifecycle status, honoring the same search filter.
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
            $key = $status instanceof ContactLifecycleStatus
                ? $status->value
                : (string) $status;

            return [$key => (int) $count];
        });

        return collect(ContactLifecycleStatus::cases())
            ->mapWithKeys(fn (ContactLifecycleStatus $case) => [
                $case->value => (int) ($counts[$case->value] ?? 0),
            ])
            ->all();
    }

    /**
     * Base query for a single board column: one status, optional search,
     * deal counts, sorted by keyset order (updated_at DESC, id DESC).
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeForBoardColumn(Builder $query, ContactLifecycleStatus $status, ?string $search = null): Builder
    {
        return $query
            ->where('status', $status->value)
            ->when($search, fn (Builder $q) => $q->search($search))
            ->withCount('deals')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /**
     * Derive lifecycle status from linked Deals, Reservations, and Contracts.
     * Priority order (highest wins): tenant → opportunity → lead → past_tenant → lost → prospect.
     */
    public function deriveLifecycleStatus(): ContactLifecycleStatus
    {
        if ($this->contracts()->whereIn('status', [
            ContractStatus::Pending->value,
            ContractStatus::Active->value,
            ContractStatus::NoticeGiven->value,
        ])->exists()) {
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

        if ($this->contracts()->where('status', ContractStatus::Ended->value)->exists()) {
            return ContactLifecycleStatus::PastTenant;
        }

        if (
            $this->deals()->exists()
            && ! $this->deals()->where('status', '!=', DealStatus::ClosedLost->value)->exists()
            && ! $this->reservations()->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::Confirmed->value,
            ])->exists()
            && ! $this->contracts()->whereIn('status', [
                ContractStatus::Pending->value,
                ContractStatus::Active->value,
                ContractStatus::NoticeGiven->value,
            ])->exists()
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

    /** @return HasMany<Interaction> */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }
}
