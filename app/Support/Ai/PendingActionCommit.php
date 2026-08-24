<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentPendingAction;
use App\Models\Employee;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Leasing\LeasingActor;
use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PendingActionCommit
{
    public function approve(AgentPendingAction $pending, Employee $approver): AgentPendingAction
    {
        /** @var array{row: AgentPendingAction, errors: array<string, list<string>>|null} $outcome */
        $outcome = DB::transaction(function () use ($pending, $approver): array {
            /** @var AgentPendingAction $row */
            $row = AgentPendingAction::query()->whereKey($pending->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== PendingActionStatus::Pending) {
                throw new ConflictHttpException('This proposal is no longer pending.');
            }

            if ($row->expires_at->isPast()) {
                $row->status = PendingActionStatus::Expired;
                $row->save();

                return [
                    'row' => $row,
                    'errors' => ['pending_action' => ['This proposal has expired.']],
                ];
            }

            $row->loadMissing(['conversation.aiAgent', 'invocation']);
            $conversation = $row->conversation;
            $principal = $conversation->principal();
            $agent = $row->agent;
            $ctx = new AgentContext(
                $principal,
                ChannelProfile::for($conversation->channel),
                $agent->definition(),
                $conversation,
                $agent,
            );

            $tool = app(ToolRegistry::class)->get($row->tool_key);
            if (! $tool instanceof ProposableTool) {
                throw new LogicException("Pending action tool [{$row->tool_key}] is not proposable.");
            }

            $arguments = $row->invocation->arguments ?? [];
            $fresh = $tool->propose($principal, $arguments, $ctx);
            if ($fresh->status !== ToolInvocationStatus::Ok) {
                $reason = $this->failureMessage($fresh);
                $row->failure_reason = $reason;
                $row->save();

                return ['row' => $row, 'errors' => ['failure_reason' => [$reason]]];
            }

            /** @var array<string, mixed> $freshPayload */
            $freshPayload = is_array($fresh->data['payload'] ?? null) ? $fresh->data['payload'] : [];

            if (AgentPendingAction::canonicalPayload($freshPayload) !== AgentPendingAction::canonicalPayload($row->payload)) {
                $reason = 'Catalogue or inputs changed since this proposal was made.';
                $row->failure_reason = $reason;
                $row->save();

                return ['row' => $row, 'errors' => ['failure_reason' => [$reason]]];
            }

            $committed = $tool->commit(
                LeasingActor::employee($approver),
                $freshPayload,
                $principal,
                $ctx,
            );

            if ($committed->status !== ToolInvocationStatus::Ok) {
                $reason = $this->failureMessage($committed);
                $row->failure_reason = $reason;
                $row->save();

                return ['row' => $row, 'errors' => ['failure_reason' => [$reason]]];
            }

            $row->status = PendingActionStatus::Approved;
            $row->resolved_by_employee_id = $approver->id;
            $row->resolved_at = now();
            $row->result_type = $committed->resultType;
            $row->result_id = $committed->resultId;
            $row->failure_reason = null;
            $row->save();

            $created = $this->createdSubject($committed);
            if ($created !== null) {
                RecordsActivity::core('agent.pending_action.approved', $created, [
                    'ai_agent_id' => $row->ai_agent_id,
                    'agent_conversation_id' => $row->agent_conversation_id,
                    'agent_pending_action_id' => $row->id,
                ], $approver);
            }

            return ['row' => $row, 'errors' => null];
        });

        if ($outcome['errors'] !== null) {
            throw ValidationException::withMessages($outcome['errors']);
        }

        return $outcome['row'];
    }

    public function reject(AgentPendingAction $pending, Employee $approver, ?string $reason): AgentPendingAction
    {
        return DB::transaction(function () use ($pending, $approver, $reason): AgentPendingAction {
            /** @var AgentPendingAction $row */
            $row = AgentPendingAction::query()->whereKey($pending->id)->lockForUpdate()->firstOrFail();

            if ($row->status !== PendingActionStatus::Pending) {
                throw new ConflictHttpException('This proposal is no longer pending.');
            }

            $row->status = PendingActionStatus::Rejected;
            $row->resolved_by_employee_id = $approver->id;
            $row->resolved_at = now();
            $row->rejection_reason = $reason;
            $row->save();

            return $row;
        });
    }

    private function failureMessage(ToolResult $result): string
    {
        if ($result->message !== null && $result->message !== '') {
            return $result->message;
        }

        if ($result->display !== '') {
            return $result->display;
        }

        return 'Proposal could not be committed.';
    }

    private function createdSubject(ToolResult $result): ?\Illuminate\Database\Eloquent\Model
    {
        if ($result->resultType === null || $result->resultId === null) {
            return null;
        }

        $class = Relation::getMorphedModel($result->resultType);
        if (! is_string($class)) {
            return null;
        }

        return $class::query()->find($result->resultId);
    }
}
