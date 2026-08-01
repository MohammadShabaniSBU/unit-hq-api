<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of a ladder/manual/cure action on a delinquency case.
 * Never updated or deleted. Ladder steps fire at most once per case (partial unique).
 *
 * @property int                      $id
 * @property int                      $delinquency_id
 * @property int|null                 $policy_step_id
 * @property DelinquencyStepAction    $action
 * @property string                   $executed_on
 * @property DelinquencyStepTrigger   $trigger
 * @property int|null                 $charge_id
 * @property int|null                 $unit_hold_id
 * @property int|null                 $contract_notice_id
 * @property int|null                 $task_id
 * @property array<string, mixed>|null $detail
 * @property int|null                 $created_by
 * @property Carbon                   $created_at
 *
 * @property-read Delinquency              $delinquency
 * @property-read DelinquencyPolicyStep|null $policyStep
 * @property-read Charge|null              $charge
 * @property-read UnitHold|null            $unitHold
 * @property-read ContractNotice|null      $contractNotice
 * @property-read Task|null                $task
 * @property-read Employee|null            $createdBy
 */
class DelinquencyStep extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'delinquency_id',
        'policy_step_id',
        'action',
        'executed_on',
        'trigger',
        'charge_id',
        'unit_hold_id',
        'contract_notice_id',
        'task_id',
        'detail',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'action' => DelinquencyStepAction::class,
            'executed_on' => 'date',
            'trigger' => DelinquencyStepTrigger::class,
            'detail' => 'array',
        ];
    }

    /** @return BelongsTo<Delinquency, $this> */
    public function delinquency(): BelongsTo
    {
        return $this->belongsTo(Delinquency::class);
    }

    /** @return BelongsTo<DelinquencyPolicyStep, $this> */
    public function policyStep(): BelongsTo
    {
        return $this->belongsTo(DelinquencyPolicyStep::class, 'policy_step_id');
    }

    /** @return BelongsTo<Charge, $this> */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** @return BelongsTo<UnitHold, $this> */
    public function unitHold(): BelongsTo
    {
        return $this->belongsTo(UnitHold::class);
    }

    /** @return BelongsTo<ContractNotice, $this> */
    public function contractNotice(): BelongsTo
    {
        return $this->belongsTo(ContractNotice::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
