<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Enums\ContractNoticeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Enums\LogChannel;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractNotice;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\SystemEvent;
use App\Models\Task;
use App\Models\UnitHold;
use App\Support\Billing\BillingMath;
use App\Support\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily delinquency run shell: eligibility, per-contract locking, step
 * execution (insert-first), cure + overlock auto-release. No runs table —
 * case timeline + Tier-2 activity are the observability.
 *
 * Step offsets evaluate against the case's anchor_due_date (pinned at open),
 * not opened_on and not the live oldest unpaid charge — so partial payments
 * that clear an older charge do not move the ladder clock.
 */
final class DelinquencyEngine
{
    /**
     * @return array{
     *     considered: int,
     *     evaluated: int,
     *     cured: int,
     *     steps_executed: int,
     *     failed: int
     * }
     */
    public function run(?int $contractId = null): array
    {
        $eligibleIds = $this->eligibleContractIds($contractId);

        $evaluated = 0;
        $cured = 0;
        $stepsExecuted = 0;
        $failed = 0;

        foreach ($eligibleIds as $id) {
            try {
                $result = DB::transaction(function () use ($id): array {
                    /** @var Contract|null $contract */
                    $contract = Contract::query()->lockForUpdate()->find($id);
                    if ($contract === null) {
                        return ['cured' => 0, 'steps' => 0];
                    }

                    return $this->evaluateLocked(
                        $contract,
                        DelinquencyCureTrigger::Payment,
                        afterPause: false,
                    );
                });

                $evaluated++;
                $cured += $result['cured'];
                $stepsExecuted += $result['steps'];
            } catch (Throwable $e) {
                $failed++;
                SystemEvent::record('delinquency.contract.failed', null, [
                    'contract_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        $summary = [
            'considered' => count($eligibleIds),
            'evaluated' => $evaluated,
            'cured' => $cured,
            'steps_executed' => $stepsExecuted,
            'failed' => $failed,
        ];

        RecordsActivity::log(
            LogChannel::Billing,
            'delinquency.run.completed',
            null,
            $summary,
            anonymous: true,
        );

        return $summary;
    }

    /**
     * Single-contract evaluation (queue job / tests). Wraps its own transaction.
     */
    public function evaluateContract(
        Contract $contract,
        DelinquencyCureTrigger $cureTrigger = DelinquencyCureTrigger::Payment,
        bool $afterPause = false,
    ): void {
        DB::transaction(function () use ($contract, $cureTrigger, $afterPause): void {
            /** @var Contract|null $locked */
            $locked = Contract::query()->lockForUpdate()->find($contract->id);
            if ($locked === null) {
                return;
            }

            $this->evaluateLocked($locked, $cureTrigger, $afterPause);
        });
    }

    /**
     * @return array{cured: int, steps: int}
     */
    private function evaluateLocked(
        Contract $contract,
        DelinquencyCureTrigger $cureTrigger,
        bool $afterPause,
    ): array {
        $contract->loadMissing(['unitItem.item.site', 'charges.allocations']);

        // Site without a policy → delinquency disabled; still cure if an open case exists.
        try {
            $site = DelinquencyState::resolveSite($contract);
        } catch (Throwable) {
            return ['cured' => 0, 'steps' => 0];
        }

        $open = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->first();

        $forceCure = in_array($cureTrigger, [
            DelinquencyCureTrigger::Vacated,
            DelinquencyCureTrigger::WriteOff,
        ], true);

        // Payment cures only when the ledger is clear; vacate / write-off always close.
        if ($open !== null && (! DelinquencyState::isDelinquent($contract) || $forceCure)) {
            DelinquencyLifecycle::cure($open, $cureTrigger);
            $open->loadMissing('policy');
            if ($open->policy?->auto_release_overlock ?? true) {
                Overlock::release($open, 'cure');
            }

            return ['cured' => 1, 'steps' => 0];
        }

        if (! DelinquencyState::isDelinquent($contract)) {
            return ['cured' => 0, 'steps' => 0];
        }

        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if (! in_array($status, [ContractStatus::Active, ContractStatus::NoticeGiven], true)) {
            return ['cured' => 0, 'steps' => 0];
        }

        if ($site->delinquency_policy_id === null) {
            return ['cured' => 0, 'steps' => 0];
        }

        $case = DelinquencyLifecycle::open($contract);
        if ($case === null) {
            return ['cured' => 0, 'steps' => 0];
        }

        $case->refresh();
        if ($case->isPaused()) {
            return ['cured' => 0, 'steps' => 0];
        }

        // Anchor clock: elapsed from case.anchor_due_date, never from opened_on
        // or the live oldest unpaid charge (partial pay must not move offsets).
        $today = DelinquencyState::siteToday($contract);
        $anchor = CarbonImmutable::parse($case->anchor_due_date->toDateString())->startOfDay();
        $elapsed = BillingMath::daysBetween($anchor, $today);

        $policySteps = DelinquencyPolicyStep::query()
            ->where('delinquency_policy_id', $case->delinquency_policy_id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $stepsExecuted = 0;
        foreach ($policySteps as $policyStep) {
            if ((int) $policyStep->offset_days > $elapsed) {
                continue;
            }

            if ($this->executeDueStep($case, $policyStep, $contract, $today, $afterPause)) {
                $stepsExecuted++;
            }
        }

        return ['cured' => 0, 'steps' => $stepsExecuted];
    }

    /**
     * Insert-first then act. Returns true when a new step row was created.
     */
    private function executeDueStep(
        Delinquency $case,
        DelinquencyPolicyStep $policyStep,
        Contract $contract,
        CarbonImmutable $today,
        bool $afterPause,
    ): bool {
        $action = $policyStep->action instanceof DelinquencyPolicyAction
            ? $policyStep->action
            : DelinquencyPolicyAction::from((string) $policyStep->action);

        $stepAction = DelinquencyStepAction::fromPolicyAction($action);
        $executedOn = $today->toDateString();

        $detail = ['incomplete' => true];
        if ($afterPause) {
            $detail['executed_after_pause'] = true;
        }

        $already = DelinquencyStep::query()
            ->where('delinquency_id', $case->id)
            ->where('policy_step_id', $policyStep->id)
            ->exists();
        if ($already) {
            return false;
        }

        try {
            $step = DelinquencyStep::query()->create([
                'delinquency_id' => $case->id,
                'policy_step_id' => $policyStep->id,
                'action' => $stepAction,
                'executed_on' => $executedOn,
                'trigger' => DelinquencyStepTrigger::Ladder,
                'detail' => $detail,
            ]);
        } catch (QueryException) {
            // Partial unique (delinquency_id, policy_step_id) — already executed.
            return false;
        }

        match ($action) {
            DelinquencyPolicyAction::AssessLateFee => $this->actAssessLateFee($step, $case, $policyStep, $contract, $executedOn),
            DelinquencyPolicyAction::RecordNotice => $this->actRecordNotice($step, $case, $policyStep, $contract),
            DelinquencyPolicyAction::CreateTask => $this->actCreateTask($step, $case, $policyStep, $contract),
            DelinquencyPolicyAction::PlaceOverlock => $this->actPlaceOverlock($step, $case),
            DelinquencyPolicyAction::RevokeAccess => $this->finishStep($step, [
                'skipped_reserved' => true,
                'incomplete' => false,
            ]),
        };

        return true;
    }

    private function actAssessLateFee(
        DelinquencyStep $step,
        Delinquency $case,
        DelinquencyPolicyStep $policyStep,
        Contract $contract,
        string $executedOn,
    ): void {
        $result = LateFeeAssessor::applyToStep(
            $step,
            $case,
            $contract,
            $policyStep->params ?? [],
            $executedOn,
        );

        $this->finishStep($step, $result['detail'], charge: $result['charge']);
    }

    private function actRecordNotice(
        DelinquencyStep $step,
        Delinquency $case,
        DelinquencyPolicyStep $policyStep,
        Contract $contract,
    ): void {
        $noticeType = ContractNoticeType::from((string) ($policyStep->params['notice_type'] ?? 'overdue'));

        $notice = ContractNotice::query()->create([
            'contract_id' => $contract->id,
            'notice_type' => $noticeType,
            'effective_date' => null,
            'required_by' => null,
            'sent_at' => null,
            'sent_channel' => null,
            'sent_to' => null,
            'document_ref' => null,
            'short_notice_reason' => null,
            'contract_item_id' => null,
            'created_by' => null,
        ]);

        $this->finishStep($step, ['incomplete' => false], contractNotice: $notice);
    }

    private function actCreateTask(
        DelinquencyStep $step,
        Delinquency $case,
        DelinquencyPolicyStep $policyStep,
        Contract $contract,
    ): void {
        $params = $policyStep->params ?? [];
        $titleKey = (string) ($params['title_key'] ?? 'delinquency.task.default');
        $urgent = (bool) ($params['urgent'] ?? false);

        $employeeId = Employee::query()->orderBy('id')->value('id');
        if ($employeeId === null) {
            $this->finishStep($step, [
                'incomplete' => false,
                'skipped_no_employee' => true,
            ]);

            return;
        }

        $title = __($titleKey);
        if ($title === $titleKey) {
            $title = str_replace(['delinquency.task.', '_'], ['', ' '], $titleKey);
            $title = ucfirst($title);
        }

        $task = Task::query()->create([
            'taskable_type' => 'contract',
            'taskable_id' => $contract->id,
            'assigned_to' => null,
            'created_by' => $employeeId,
            'title' => $title,
            'description' => null,
            'priority' => $urgent ? 'urgent' : 'medium',
            'status' => 'open',
            'type' => 'follow_up',
            'due_at' => null,
            'remind_at' => null,
            'completed_at' => null,
        ]);

        $this->finishStep($step, [
            'incomplete' => false,
            'title_key' => $titleKey,
        ], task: $task);
    }

    private function actPlaceOverlock(DelinquencyStep $step, Delinquency $case): void
    {
        $result = Overlock::place($case);
        $holds = is_array($result) ? $result : [$result];
        /** @var UnitHold $primary */
        $primary = $holds[0];

        $detail = [
            'incomplete' => false,
            'unit_hold_ids' => array_map(fn (UnitHold $h): int => (int) $h->id, $holds),
        ];

        $this->finishStep($step, $detail, unitHold: $primary);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function finishStep(
        DelinquencyStep $step,
        array $detail,
        ?Charge $charge = null,
        ?UnitHold $unitHold = null,
        ?ContractNotice $contractNotice = null,
        ?Task $task = null,
    ): void {
        $merged = array_merge($step->detail ?? [], $detail);
        unset($merged['incomplete']);
        if (($detail['incomplete'] ?? false) === true) {
            $merged['incomplete'] = true;
        }

        $step->forceFill([
            'charge_id' => $charge?->id ?? $step->charge_id,
            'unit_hold_id' => $unitHold?->id ?? $step->unit_hold_id,
            'contract_notice_id' => $contractNotice?->id ?? $step->contract_notice_id,
            'task_id' => $task?->id ?? $step->task_id,
            'detail' => $merged,
        ])->save();
    }

    /**
     * @return list<int>
     */
    private function eligibleContractIds(?int $contractId): array
    {
        $query = Contract::query()
            ->whereIn('status', [ContractStatus::Active, ContractStatus::NoticeGiven])
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('contract_items')
                    ->join('units', function ($join): void {
                        $join->on('units.id', '=', 'contract_items.item_id')
                            ->where('contract_items.item_type', '=', 'unit');
                    })
                    ->join('sites', 'sites.id', '=', 'units.site_id')
                    ->whereColumn('contract_items.contract_id', 'contracts.id')
                    ->whereNull('contract_items.effective_to')
                    ->whereNotNull('sites.delinquency_policy_id');
            })
            ->orderBy('id');

        if ($contractId !== null) {
            $query->where('id', $contractId);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
