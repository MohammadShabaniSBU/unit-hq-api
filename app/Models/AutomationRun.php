<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A single execution instance of an automation.
 *
 * The `trigger_payload` stores the raw event that initiated the run
 * (e.g. the contact object that was created or updated).
 *
 * The `context` is the merged variable bag built up as steps execute,
 * available to all downstream nodes as `{{run.context.*}}`.
 *
 * @property int                       $id
 * @property int|null                  $automation_id
 * @property AutomationRunStatus       $status
 * @property string|null               $triggered_by
 * @property array<string, mixed>|null $trigger_payload
 * @property array<string, mixed>|null $context
 * @property Carbon|null               $started_at
 * @property Carbon|null               $completed_at
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 *
 * @property-read Automation|null                     $automation
 * @property-read Collection<int, AutomationRunStep>  $steps
 */
class AutomationRun extends Model
{
    protected $fillable = [
        'automation_id',
        'status',
        'triggered_by',
        'trigger_payload',
        'context',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'          => AutomationRunStatus::class,
            'trigger_payload' => 'array',
            'context'         => 'array',
            'started_at'      => 'datetime',
            'completed_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<Automation, AutomationRun> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return HasMany<AutomationRunStep> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class, 'run_id');
    }
}
