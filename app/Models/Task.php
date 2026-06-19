<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Polymorphic task. Attachable to Deal, Contact, Lease, Unit, or any future
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
class Task extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'taskable_type',
        'taskable_id',
        'assigned_to',
        'created_by',
        'title',
        'description',
        'priority',
        'status',
        'due_at',
        'remind_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at'       => 'datetime',
            'remind_at'    => 'datetime',
            'completed_at' => 'datetime',
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
