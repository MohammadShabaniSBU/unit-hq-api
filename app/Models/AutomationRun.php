<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A single execution instance of an automation.
 *
 * `trigger_payload` is the immutable snapshot that started the run.
 * Step outputs live on automation_run_steps — TokenResolver builds an in-memory bag from those.
 *
 * @property int                            $id
 * @property int|null                       $automation_id
 * @property int|null                       $trigger_node_id
 * @property string|null                    $subject_type
 * @property int|null                       $subject_id
 * @property string|null                    $causer_type
 * @property int|null                       $causer_id
 * @property int|null                       $root_run_id
 * @property int                            $depth
 * @property AutomationRunStatus            $status
 * @property array<string, mixed>|null      $trigger_payload
 * @property array<string, mixed>|null      $guard
 * @property string|null                    $error
 * @property AutomationCancelCause|null     $cancel_cause
 * @property int|null                       $cancelled_by
 * @property Carbon|null                    $waiting_until
 * @property int|null                       $current_node_id
 * @property string|null                    $active_key
 * @property Carbon|null                    $started_at
 * @property Carbon|null                    $completed_at
 * @property Carbon                         $created_at
 * @property Carbon                         $updated_at
 *
 * @property-read Automation|null                     $automation
 * @property-read AutomationNode|null                 $triggerNode
 * @property-read AutomationNode|null                 $currentNode
 * @property-read AutomationRun|null                  $rootRun
 * @property-read Employee|null                       $cancelledBy
 * @property-read Collection<int, AutomationRunStep>  $steps
 */
class AutomationRun extends Model
{
    protected $fillable = [
        'automation_id',
        'trigger_node_id',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'root_run_id',
        'depth',
        'status',
        'trigger_payload',
        'guard',
        'error',
        'cancel_cause',
        'cancelled_by',
        'waiting_until',
        'current_node_id',
        'active_key',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'trigger_payload' => 'array',
            'guard' => 'array',
            'cancel_cause' => AutomationCancelCause::class,
            'depth' => 'integer',
            'waiting_until' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Automation, AutomationRun> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return BelongsTo<AutomationNode, AutomationRun> */
    public function triggerNode(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'trigger_node_id');
    }

    /** @return BelongsTo<AutomationNode, AutomationRun> */
    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'current_node_id');
    }

    /** @return BelongsTo<AutomationRun, AutomationRun> */
    public function rootRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'root_run_id');
    }

    /** @return BelongsTo<Employee, AutomationRun> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cancelled_by');
    }

    /** @return MorphTo<Model, AutomationRun> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, AutomationRun> */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<AutomationRunStep> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class, 'run_id');
    }
}
