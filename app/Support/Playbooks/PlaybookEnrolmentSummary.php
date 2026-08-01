<?php

declare(strict_types=1);

namespace App\Support\Playbooks;

use App\Enums\AutomationNodeKind;
use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Delinquency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Shared enrolment presentation helpers for playbook lineage + cross-links.
 */
final class PlaybookEnrolmentSummary
{
    /** @return list<AutomationRunStatus> */
    public static function activeStatuses(): array
    {
        return [
            AutomationRunStatus::Pending,
            AutomationRunStatus::Running,
            AutomationRunStatus::Waiting,
        ];
    }

    /** @return list<AutomationRunStatus> */
    public static function exitedStatuses(): array
    {
        return [
            AutomationRunStatus::Succeeded,
            AutomationRunStatus::Failed,
            AutomationRunStatus::Cancelled,
        ];
    }

    /**
     * @return array{
     *     playbook_id: int,
     *     run_id: int,
     *     automation_id: int,
     *     step_index: int,
     *     step_total: int,
     *     waiting_until: string|null,
     *     status: string
     * }|null
     */
    public static function activeForSubject(string $subjectType, int $subjectId): ?array
    {
        $run = AutomationRun::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereIn('status', self::activeStatuses())
            ->whereHas('automation', fn ($q) => $q->whereNotNull('playbook_id'))
            ->with(['automation.nodes', 'steps'])
            ->latest('id')
            ->first();

        if ($run === null || $run->automation === null || $run->automation->playbook_id === null) {
            return null;
        }

        $progress = self::progress($run, $run->automation);

        return [
            'playbook_id' => (int) $run->automation->playbook_id,
            'run_id' => (int) $run->id,
            'automation_id' => (int) $run->automation_id,
            'step_index' => $progress['steps_completed'],
            'step_total' => $progress['step_total'],
            'waiting_until' => $run->waiting_until?->toDateTimeString(),
            'status' => $run->status instanceof AutomationRunStatus
                ? $run->status->value
                : (string) $run->status,
        ];
    }

    /**
     * @return array{steps_completed: int, step_total: int}
     */
    public static function progress(AutomationRun $run, ?Automation $automation = null): array
    {
        $automation ??= $run->relationLoaded('automation')
            ? $run->automation
            : Automation::query()->with('nodes')->find($run->automation_id);

        $actionNodeIds = [];
        if ($automation !== null) {
            $nodes = $automation->relationLoaded('nodes')
                ? $automation->nodes
                : $automation->nodes()->get();

            foreach ($nodes as $node) {
                $type = $node->type instanceof AutomationNodeType
                    ? $node->type
                    : AutomationNodeType::tryFrom((string) $node->type);

                if ($type !== null && $type->kind() === AutomationNodeKind::Action) {
                    $actionNodeIds[] = (int) $node->id;
                }
            }
        }

        $stepTotal = count($actionNodeIds);
        $completed = 0;

        $steps = $run->relationLoaded('steps')
            ? $run->steps
            : $run->steps()->get();

        foreach ($steps as $step) {
            if ($step->node_id === null) {
                continue;
            }
            if (! in_array((int) $step->node_id, $actionNodeIds, true)) {
                continue;
            }
            $status = $step->status instanceof AutomationRunStepStatus
                ? $step->status
                : AutomationRunStepStatus::tryFrom((string) $step->status);
            if ($status === AutomationRunStepStatus::Succeeded || $status === AutomationRunStepStatus::Skipped) {
                $completed++;
            }
        }

        return [
            'steps_completed' => $completed,
            'step_total' => $stepTotal,
        ];
    }

    /**
     * Batch-load subject models for a page of runs (avoids N+1).
     *
     * @param  Collection<int, AutomationRun>|SupportCollection<int, AutomationRun>  $runs
     * @return array{delinquencies: Collection<int, Delinquency>, deals: Collection<int, Deal>}
     */
    public static function loadSubjects($runs): array
    {
        $delinquencyIds = [];
        $dealIds = [];

        foreach ($runs as $run) {
            if ($run->subject_type === 'delinquency' && $run->subject_id !== null) {
                $delinquencyIds[] = (int) $run->subject_id;
            }
            if ($run->subject_type === 'deal' && $run->subject_id !== null) {
                $dealIds[] = (int) $run->subject_id;
            }
        }

        $delinquencies = $delinquencyIds === []
            ? new Collection
            : Delinquency::query()
                ->whereIn('id', array_unique($delinquencyIds))
                ->with(['contract.contact'])
                ->get()
                ->keyBy('id');

        $deals = $dealIds === []
            ? new Collection
            : Deal::query()
                ->whereIn('id', array_unique($dealIds))
                ->with(['contact'])
                ->get()
                ->keyBy('id');

        return [
            'delinquencies' => $delinquencies,
            'deals' => $deals,
        ];
    }

    /**
     * @param  array{delinquencies: Collection<int, Delinquency>, deals: Collection<int, Deal>}  $subjects
     * @return array<string, mixed>
     */
    public static function subjectPayload(AutomationRun $run, array $subjects): array
    {
        $type = $run->subject_type;
        $id = $run->subject_id !== null ? (int) $run->subject_id : null;

        $base = [
            'type' => $type,
            'id' => $id,
            'contact' => null,
            'contract' => null,
            'deal' => null,
            'cure_trigger' => null,
            'deal_status' => null,
        ];

        if ($type === 'delinquency' && $id !== null) {
            /** @var Delinquency|null $case */
            $case = $subjects['delinquencies']->get($id);
            if ($case === null) {
                return $base;
            }

            $contract = $case->contract;
            $contact = $contract?->contact;

            $base['cure_trigger'] = $case->cure_trigger instanceof \BackedEnum
                ? $case->cure_trigger->value
                : $case->cure_trigger;
            $base['contract'] = $contract !== null
                ? ['id' => (int) $contract->id]
                : null;
            $base['contact'] = self::contactPayload($contact);

            return $base;
        }

        if ($type === 'deal' && $id !== null) {
            /** @var Deal|null $deal */
            $deal = $subjects['deals']->get($id);
            if ($deal === null) {
                return $base;
            }

            $base['deal'] = ['id' => (int) $deal->id];
            $base['deal_status'] = $deal->status instanceof \BackedEnum
                ? $deal->status->value
                : (string) $deal->status;
            $base['contact'] = self::contactPayload($deal->contact);

            return $base;
        }

        return $base;
    }

    /** @return array{id: int, name: string}|null */
    private static function contactPayload(?Contact $contact): ?array
    {
        if ($contact === null) {
            return null;
        }

        $name = trim($contact->first_name.' '.$contact->last_name);

        return [
            'id' => (int) $contact->id,
            'name' => $name !== '' ? $name : ($contact->email ?? '#'.$contact->id),
        ];
    }
}
