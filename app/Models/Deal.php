<?php

namespace App\Models;

use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Enums\StayPeriod;
use App\Models\Concerns\HasAiSummary;
use App\Models\Concerns\HasAutomationTriggers;
use App\Models\Concerns\HasNotes;
use App\Models\Concerns\LogsDirtyActivity;
use App\Support\Auth\Concerns\VisibleToEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The pursuit record. Starts when someone expresses interest and ends when
 * they become a tenant or walk away. Pipeline stage is stored on `status`.
 *
 * offer_sent and offer_viewed are cached here for simple pipeline reporting,
 * even though the linked Offer record also tracks send/view events.
 *
 * Expected values live on Deal. Actual values live on Contract.
 *
 * @property int $id
 * @property int $contact_id
 * @property int|null $site_id
 * @property DealStatus $status
 * @property string|null $expected_move_in Y-m-d
 * @property int|null $expected_stay_length
 * @property StayPeriod|null $expected_stay_period
 * @property string|null $desired_size NUMERIC(8,2)
 * @property int|null $desired_unit_class_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Contact                          $contact
 * @property-read Site|null                        $site
 * @property-read UnitClass|null                   $desiredUnitClass
 * @property-read Collection<int, Offer>           $offers
 * @property-read Collection<int, Reservation>     $reservations
 * @property-read Collection<int, Contract>         $contracts
 * @property-read Collection<int, Task>            $tasks
 * @property-read Collection<int, Note>              $notes
 */
class Deal extends Model
{
    use HasAiSummary, HasAutomationTriggers, HasFactory, HasNotes, LogsDirtyActivity, VisibleToEmployee;

    protected function activityLogChannel(): LogChannel
    {
        return LogChannel::Crm;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogChannel()->value)
            ->logFillable()
            ->logExcept(['status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    /**
     * Store desired unit class as {id, label} so the activity timeline can show the dropdown name.
     */
    public function beforeActivityLogged(Activity $activity, string $event): void
    {
        $changes = $activity->attribute_changes;
        if ($changes === null || $changes->isEmpty()) {
            return;
        }

        $attributes = is_array($changes->get('attributes')) ? $changes->get('attributes') : [];
        $old = is_array($changes->get('old')) ? $changes->get('old') : [];

        $hasInAttributes = array_key_exists('desired_unit_class_id', $attributes);
        $hasInOld = array_key_exists('desired_unit_class_id', $old);

        if (! $hasInAttributes && ! $hasInOld) {
            return;
        }

        $ids = [];
        if ($hasInAttributes) {
            $id = self::positiveIntOrNull($attributes['desired_unit_class_id']);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        if ($hasInOld) {
            $id = self::positiveIntOrNull($old['desired_unit_class_id']);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        /** @var SupportCollection<int, string|null> $labels */
        $labels = $ids === []
            ? collect()
            : UnitClass::query()->whereIn('id', array_values(array_unique($ids)))->pluck('label', 'id');

        if ($hasInAttributes) {
            $attributes['desired_unit_class_id'] = self::unitClassRef(
                $attributes['desired_unit_class_id'],
                $labels
            );
        }
        if ($hasInOld) {
            $old['desired_unit_class_id'] = self::unitClassRef(
                $old['desired_unit_class_id'],
                $labels
            );
        }

        $merged = $changes->all();
        if ($hasInAttributes || array_key_exists('attributes', $merged)) {
            $merged['attributes'] = $attributes;
        }
        if ($hasInOld || array_key_exists('old', $merged)) {
            $merged['old'] = $old;
        }

        $activity->attribute_changes = collect($merged);
    }

    /**
     * @param  SupportCollection<int, string|null>  $labels
     * @return array{id: int, label: string|null}|null
     */
    private static function unitClassRef(mixed $value, SupportCollection $labels): ?array
    {
        $id = self::positiveIntOrNull($value);
        if ($id === null) {
            return null;
        }

        $label = $labels->get($id);

        return [
            'id' => $id,
            'label' => is_string($label) ? $label : null,
        ];
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    protected $fillable = [
        'contact_id',
        'site_id',
        'status',
        'expected_move_in',
        'expected_stay_length',
        'expected_stay_period',
        'desired_size',
        'desired_unit_class_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'expected_move_in' => 'date',
            'expected_stay_period' => StayPeriod::class,
            'desired_size' => 'decimal:2',
        ];
    }

    public function isActivePursuit(): bool
    {
        return $this->status->isActivePursuit();
    }

    /**
     * Search by deal id or linked contact name / email / company.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return $query->where(function (Builder $q) use ($term, $digits) {
            if ($digits !== '') {
                $q->where('id', $digits);
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
     * Grouped count of deals per pipeline status, honoring the same search filter.
     * Returns every status key (including zero counts), in enum order.
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

        $counts = collect($raw)->mapWithKeys(function (mixed $count, mixed $status) {
            $key = $status instanceof DealStatus
                ? $status->value
                : (string) $status;

            return [$key => (int) $count];
        });

        return collect(DealStatus::cases())
            ->mapWithKeys(fn (DealStatus $case) => [
                $case->value => (int) ($counts[$case->value] ?? 0),
            ])
            ->all();
    }

    /**
     * Base query for a single board column: one status, optional search,
     * contact + unit class, sorted by keyset order (updated_at DESC, id DESC).
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function scopeForBoardColumn(Builder $query, DealStatus $status, ?string $search = null): Builder
    {
        return $query
            ->where('status', $status->value)
            ->when($search, fn (Builder $q) => $q->search($search))
            ->with(['contact', 'desiredUnitClass'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /** @return BelongsTo<Contact, Deal> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Site, Deal> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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

    /** @return HasMany<Reservation> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasMany<Contract, Deal> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return MorphMany<Task> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }
}
