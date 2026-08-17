<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Support\Ai\CopilotDispatcher;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;

class CopilotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $conversations = $employee->conversations()
            ->whereNull('deleted_at')
            ->latest('updated_at')
            ->paginate($this->perPage(20));

        return $this->paginated($conversations, 'Conversations retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => $validated['title'] ?? 'New conversation',
            'site_scope_snapshot' => $employee->siteIdsFor(Permission::ContactView),
        ]);

        return $this->created([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ], 'Conversation created successfully.');
    }

    public function show(Request $request, CopilotConversation $conversation): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($this->perPage(50));

        return $this->paginated($messages, 'Conversation messages retrieved successfully.');
    }

    public function destroy(Request $request, CopilotConversation $conversation): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);
        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return $this->noContent('Conversation archived successfully.');
    }

    public function storeMessage(Request $request, CopilotConversation $conversation): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);
        Gate::authorize('view', $conversation);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'client_message_id' => ['required', 'uuid'],
            'source' => ['sometimes', 'string', 'in:text,voice'],
        ]);

        if ($conversation->title === 'New conversation') {
            $conversation->forceFill([
                'title' => Str::limit($validated['message'], 50, '...'),
            ])->save();
        }

        return $this->accepted(
            CopilotDispatcher::dispatchTurn($conversation, $employee, $validated),
            'Copilot turn accepted.',
        );
    }

    public function storeDecisions(Request $request, CopilotConversation $conversation): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);
        Gate::authorize('view', $conversation);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*' => ['required', 'array'],
            'decisions.*.action' => ['required', 'string', 'in:approve,reject'],
            'decisions.*.result' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'source' => ['sometimes', 'string', 'in:text,voice'],
        ]);

        $normalized = [];
        foreach ($validated['decisions'] as $toolCallId => $decision) {
            $normalized[$toolCallId] = $decision['action'] === 'approve'
                ? Decision::approve()
                : Decision::reject($decision['result'] ?? null);
        }

        $decisions = Decisions::from($normalized)->rejectRemaining();

        return $this->accepted(
            CopilotDispatcher::dispatchDecisions(
                $conversation,
                $employee,
                $decisions,
                $validated['source'] ?? 'text',
            ),
            'Copilot decisions accepted.',
        );
    }
}
