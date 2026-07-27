<?php

namespace App\Models;

use App\Enums\AutomationRunStepStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * The execution record for a single node within a run.
 *
 * `node_type` is denormalized — it snapshots the type at execution time
 * so historical records remain queryable even after the node is deleted.
 *
 * `input` contains data passed to the node (merged context + edge output).
 * `output` contains data produced by the node (published back to context).
 * `error` contains failure detail: { message, code, trace? }
 *
 * @property int                       $id
 * @property int                       $run_id
 * @property int|null                  $node_id
 * @property string                    $node_type
 * @property AutomationRunStepStatus   $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $error
 * @property Carbon|null               $started_at
 * @property Carbon|null               $completed_at
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 *
 * @property-read AutomationRun       $run
 * @property-read AutomationNode|null $node
 */
class AutomationRunStep extends Model
{
    protected $fillable = [
        'run_id',
        'node_id',
        'node_type',
        'status',
        'input',
        'output',
        'error',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'status'       => AutomationRunStepStatus::class,
            'input'        => 'array',
            'output'       => 'array',
            'error'        => 'array',
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms'  => 'integer',
        ];
    }

    /** @return BelongsTo<AutomationRun, AutomationRunStep> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id');
    }

    /** @return BelongsTo<AutomationNode, AutomationRunStep> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'node_id');
    }
}
