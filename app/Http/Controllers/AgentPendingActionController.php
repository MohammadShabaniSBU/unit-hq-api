<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AgentPendingActionResource;
use App\Models\AgentPendingAction;
use App\Models\Employee;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\PendingActionCommit;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgentPendingActionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AgentActionApprove->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(PendingActionStatus::class)],
            'ai_agent_id' => ['sometimes', 'integer', 'exists:ai_agents,id'],
            'tool_key' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = AgentPendingAction::query()
            ->visibleTo($employee, Permission::AgentActionApprove)
            ->operatorQueue()
            ->with(['agent', 'conversation.contact', 'conversation.aiAgent'])
            ->latest('id');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['ai_agent_id'])) {
            $query->where('ai_agent_id', $validated['ai_agent_id']);
        }
        if (isset($validated['tool_key'])) {
            $query->where('tool_key', $validated['tool_key']);
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (AgentPendingAction $action) => AgentPendingActionResource::make($action),
            ),
            'Pending actions retrieved successfully.',
        );
    }

    public function badge(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AgentActionApprove->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $pending = AgentPendingAction::query()
            ->visibleTo($employee, Permission::AgentActionApprove)
            ->operatorQueue()
            ->where('status', PendingActionStatus::Pending)
            ->count();

        return $this->success(
            ['pending' => $pending],
            'Pending action badge retrieved successfully.',
        );
    }

    public function show(AgentPendingAction $agentPendingAction): JsonResponse
    {
        Gate::authorize(Permission::AgentActionApprove->value, $agentPendingAction);
        $this->authorize('view', $agentPendingAction);

        $agentPendingAction->load(['agent', 'conversation.aiAgent', 'conversation.contact', 'invocation.pendingAction']);
        $agentPendingAction->conversation->setRelation(
            'messages',
            $agentPendingAction->conversation->messages()->orderByDesc('sequence')->limit(20)->get()->reverse()->values(),
        );

        return $this->success(
            AgentPendingActionResource::make($agentPendingAction),
            'Pending action retrieved successfully.',
        );
    }

    public function approve(Request $request, AgentPendingAction $agentPendingAction): JsonResponse
    {
        Gate::authorize(Permission::AgentActionApprove->value, $agentPendingAction);
        $this->authorize('approve', $agentPendingAction);

        /** @var Employee $employee */
        $employee = $request->user();

        $action = (new PendingActionCommit)->approve($agentPendingAction, $employee);
        $action->load(['agent', 'conversation', 'invocation', 'result']);

        return $this->success(
            AgentPendingActionResource::make($action),
            'Proposal approved.',
        );
    }

    public function reject(Request $request, AgentPendingAction $agentPendingAction): JsonResponse
    {
        Gate::authorize(Permission::AgentActionApprove->value, $agentPendingAction);
        $this->authorize('reject', $agentPendingAction);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        $action = (new PendingActionCommit)->reject(
            $agentPendingAction,
            $employee,
            isset($validated['reason']) ? (string) $validated['reason'] : null,
        );

        return $this->success(
            AgentPendingActionResource::make($action),
            'Proposal rejected.',
        );
    }
}
