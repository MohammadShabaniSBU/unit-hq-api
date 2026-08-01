<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DelinquencyCureTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fact that a contract entered (and eventually left) delinquency.
 * Severity is never stored — computed from charges (invariant 5).
 * Append-only aside from cured_on / pause fields set once.
 *
 * @property int                          $id
 * @property int                          $contract_id
 * @property int                          $delinquency_policy_id
 * @property string                       $anchor_due_date
 * @property string                       $opened_on
 * @property string|null                  $cured_on
 * @property DelinquencyCureTrigger|null  $cure_trigger
 * @property Carbon|null                  $paused_at
 * @property string|null                  $paused_reason
 * @property int|null                     $paused_by
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 *
 * @property-read Contract                $contract
 * @property-read DelinquencyPolicy       $policy
 * @property-read Employee|null           $pausedBy
 * @property-read Collection<int, DelinquencyStep> $steps
 */
class Delinquency extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'delinquency_policy_id',
        'anchor_due_date',
        'opened_on',
        'cured_on',
        'cure_trigger',
        'paused_at',
        'paused_reason',
        'paused_by',
    ];

    protected function casts(): array
    {
        return [
            'anchor_due_date' => 'date',
            'opened_on' => 'date',
            'cured_on' => 'date',
            'cure_trigger' => DelinquencyCureTrigger::class,
            'paused_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->cured_on === null;
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null && $this->isOpen();
    }

    /** @param Builder<Delinquency> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('cured_on');
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<DelinquencyPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(DelinquencyPolicy::class, 'delinquency_policy_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function pausedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'paused_by');
    }

    /** @return HasMany<DelinquencyStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(DelinquencyStep::class)->orderBy('executed_on')->orderBy('id');
    }

    /**
     * Append-only timeline of executed steps with artefact relations.
     *
     * @return Collection<int, DelinquencyStep>
     */
    public function timeline(): Collection
    {
        return $this->steps()
            ->with(['policyStep', 'charge', 'unitHold', 'contractNotice', 'task', 'createdBy'])
            ->get();
    }
}
