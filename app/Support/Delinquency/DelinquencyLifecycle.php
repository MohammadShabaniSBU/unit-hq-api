<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Jobs\EvaluateRunGuards;
use App\Models\AccessSuspension;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractNotice;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\Task;
use App\Models\UnitHold;
use App\Support\RecordsActivity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Open / cure / pause / resume / recordStep for delinquency cases.
 * Engine (S07-02) calls these; tests call them directly.
 */
final class DelinquencyLifecycle
{
    /**
     * Open a case when the contract is delinquent, eligible, has a site policy,
     * and has no open case. Returns existing open case if already open; null if ineligible.
     */
    public static function open(Contract $contract): ?Delinquency
    {
        $existing = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if (! in_array($status, [ContractStatus::Active, ContractStatus::NoticeGiven], true)) {
            return null;
        }

        if (! DelinquencyState::isDelinquent($contract)) {
            return null;
        }

        $site = DelinquencyState::resolveSite($contract);
        if ($site->delinquency_policy_id === null) {
            return null;
        }

        $overdue = DelinquencyState::overdueCharges($contract);
        /** @var Charge $oldest */
        $oldest = $overdue->first();
        $today = DelinquencyState::siteToday($contract)->toDateString();

        return Delinquency::query()->create([
            'contract_id' => $contract->id,
            'delinquency_policy_id' => $site->delinquency_policy_id,
            'anchor_due_date' => $oldest->due_date->toDateString(),
            'opened_on' => $today,
        ]);
    }

    public static function cure(Delinquency $delinquency, DelinquencyCureTrigger $trigger): Delinquency
    {
        if (! $delinquency->isOpen()) {
            throw new InvalidArgumentException('Cannot cure an already-cured delinquency case.');
        }

        $contract = $delinquency->contract;
        $today = DelinquencyState::siteToday($contract)->toDateString();

        $delinquency->forceFill([
            'cured_on' => $today,
            'cure_trigger' => $trigger,
            'paused_at' => null,
            'paused_reason' => null,
            'paused_by' => null,
        ])->save();

        self::recordStep(
            delinquency: $delinquency,
            action: DelinquencyStepAction::Cure,
            trigger: DelinquencyStepTrigger::Cure,
            executedOn: $today,
            policyStep: null,
        );

        RecordsActivity::core('delinquency.cured', $delinquency, [
            'cure_trigger' => $trigger->value,
            'cured_on' => $today,
            'contract_id' => $delinquency->contract_id,
        ], anonymous: true);

        $caseId = (int) $delinquency->id;
        DB::afterCommit(static function () use ($caseId): void {
            EvaluateRunGuards::dispatch('delinquency', $caseId);
        });

        return $delinquency->fresh() ?? $delinquency;
    }

    public static function pause(Delinquency $delinquency, string $reason, Employee $by): Delinquency
    {
        if (! $delinquency->isOpen()) {
            throw new InvalidArgumentException('Cannot pause a cured delinquency case.');
        }

        if ($delinquency->isPaused()) {
            throw new InvalidArgumentException('Delinquency case is already paused.');
        }

        $delinquency->forceFill([
            'paused_at' => now(),
            'paused_reason' => $reason,
            'paused_by' => $by->id,
        ])->save();

        $today = DelinquencyState::siteToday($delinquency->contract)->toDateString();
        self::recordStep(
            delinquency: $delinquency,
            action: DelinquencyStepAction::Pause,
            trigger: DelinquencyStepTrigger::Manual,
            executedOn: $today,
            detail: ['reason' => $reason],
            createdBy: $by,
        );

        RecordsActivity::core('delinquency.paused', $delinquency, [
            'reason' => $reason,
            'contract_id' => $delinquency->contract_id,
        ], causer: $by);

        return $delinquency->fresh() ?? $delinquency;
    }

    /**
     * Clear pause fields. Returns true so callers mark backfill steps with
     * detail.executed_after_pause (elapsed-while-paused steps still fire).
     */
    public static function resume(Delinquency $delinquency, Employee $by): bool
    {
        if (! $delinquency->isOpen()) {
            throw new InvalidArgumentException('Cannot resume a cured delinquency case.');
        }

        if (! $delinquency->isPaused()) {
            return false;
        }

        $reason = $delinquency->paused_reason;

        $delinquency->forceFill([
            'paused_at' => null,
            'paused_reason' => null,
            'paused_by' => null,
        ])->save();

        $today = DelinquencyState::siteToday($delinquency->contract)->toDateString();
        self::recordStep(
            delinquency: $delinquency,
            action: DelinquencyStepAction::Resume,
            trigger: DelinquencyStepTrigger::Manual,
            executedOn: $today,
            detail: ['prior_reason' => $reason],
            createdBy: $by,
        );

        RecordsActivity::core('delinquency.resumed', $delinquency, [
            'prior_reason' => $reason,
            'contract_id' => $delinquency->contract_id,
        ], causer: $by);

        return true;
    }

    /**
     * Append-only step insert. When $afterPause, sets detail.executed_after_pause.
     *
     * @param  array<string, mixed>|null  $detail
     */
    public static function recordStep(
        Delinquency $delinquency,
        DelinquencyStepAction $action,
        DelinquencyStepTrigger $trigger,
        string $executedOn,
        ?DelinquencyPolicyStep $policyStep = null,
        ?Charge $charge = null,
        ?UnitHold $unitHold = null,
        ?ContractNotice $contractNotice = null,
        ?Task $task = null,
        ?AccessSuspension $accessSuspension = null,
        ?array $detail = null,
        ?Employee $createdBy = null,
        bool $afterPause = false,
    ): DelinquencyStep {
        if ($afterPause) {
            $detail = array_merge($detail ?? [], ['executed_after_pause' => true]);
        }

        return DelinquencyStep::query()->create([
            'delinquency_id' => $delinquency->id,
            'policy_step_id' => $policyStep?->id,
            'action' => $action,
            'executed_on' => $executedOn,
            'trigger' => $trigger,
            'charge_id' => $charge?->id,
            'unit_hold_id' => $unitHold?->id,
            'contract_notice_id' => $contractNotice?->id,
            'task_id' => $task?->id,
            'access_suspension_id' => $accessSuspension?->id,
            'detail' => $detail,
            'created_by' => $createdBy?->id,
        ]);
    }

    /**
     * Ensure an open case exists for a delinquent eligible contract, or cure
     * the open case when no longer delinquent. Returns the open case or null.
     */
    public static function ensureOpenOrCure(
        Contract $contract,
        DelinquencyCureTrigger $cureTrigger = DelinquencyCureTrigger::Payment,
    ): ?Delinquency {
        $open = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->first();

        if (DelinquencyState::isDelinquent($contract)) {
            return $open ?? self::open($contract);
        }

        if ($open !== null) {
            self::cure($open, $cureTrigger);
        }

        return null;
    }

    public static function openOrFail(Contract $contract): Delinquency
    {
        $case = self::open($contract);
        if ($case === null) {
            throw new RuntimeException("Could not open delinquency case for contract {$contract->id}.");
        }

        return $case;
    }
}
