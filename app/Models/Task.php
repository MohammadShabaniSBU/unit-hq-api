<?php

namespace App\Models;

use App\Enums\TaskType;
use App\Support\Auth\Concerns\VisibleToEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Polymorphic task. Attachable to Deal, Contact, Contract, Unit, or any future
 * entity — add it as a morph target with no schema change.
 *
 * completed_at is stored alongside status = done so that SLA reporting can
 * use the timestamp. status alone would lose when completion happened.
 *
 * remind_at is channel-agnostic. The reminder scheduler queries:
 *   WHERE remind_at <= now() AND status NOT IN ('done', 'cancelled')
 *
 * @property int         $id
 * @property string      $taskable_type
 * @property int         $taskable_id
 * @property int|null    $assigned_to
 * @property int         $created_by
 * @property string      $title
 * @property string|null $description
 * @property string      $priority     low|medium|high|urgent
 * @property string      $status       open|in_progress|done|cancelled
 * @property string|null $type         call|email|follow_up|unit_tour|other
 * @property Carbon|null $due_at
 * @property Carbon|null $remind_at
 * @property Carbon|null $completed_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Model $taskable
 * @property-read Employee|null $assignee
 * @property-read Employee      $creator
 */
class Task extends Model
{
    use HasFactory, VisibleToEmployee;

    /** @var array<int, string> */
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    /** @var array<int, string> */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'taskable_type',
        'taskable_id',
        'assigned_to',
        'created_by',
        'title',
        'description',
        'priority',
        'status',
        'type',
        'due_at',
        'remind_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type'         => TaskType::class,
            'due_at'       => 'datetime',
            'remind_at'    => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Search by title, description, id, or linked contact name.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");

            if ($digits !== '') {
                $q->orWhere('id', $digits);
            }

            $q->orWhere(function (Builder $morph) use ($term) {
                $morph->where('taskable_type', Contact::class)
                    ->whereHasMorph('taskable', [Contact::class], function (Builder $contactQuery) use ($term) {
                        $contactQuery->where(function (Builder $inner) use ($term) {
                            $inner->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%")
                                ->orWhere('company', 'like', "%{$term}%");
                        });
                    });
            });
        });
    }

    /**
     * Grouped count of tasks per status, honoring the same search filter.
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
     * assignee + taskable, due date ascending (nulls last).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForBoardColumn(Builder $query, string $status, ?string $search = null): Builder
    {
        return $query
            ->where('status', $status)
            ->when($search, fn (Builder $q) => $q->search($search))
            ->with(['assignee', 'taskable'])
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /**
     * Short payload describing the parent entity for list/board cards.
     *
     * @return array{type: string, id: int, label: string}|null
     */
    public function taskablePayload(): ?array
    {
        if (! $this->relationLoaded('taskable') || $this->taskable === null) {
            return null;
        }

        $type = Str::snake(class_basename($this->taskable_type));
        $label = match (true) {
            $this->taskable instanceof Contact => trim("{$this->taskable->first_name} {$this->taskable->last_name}"),
            $this->taskable instanceof Deal => 'Deal #' . $this->taskable->id,
            default => class_basename($this->taskable_type) . ' #' . $this->taskable_id,
        };

        return [
            'type' => $type,
            'id' => (int) $this->taskable_id,
            'label' => $label !== '' ? $label : class_basename($this->taskable_type) . ' #' . $this->taskable_id,
        ];
    }

    /** @return MorphTo */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Employee, Task> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    /** @return BelongsTo<Employee, Task> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
