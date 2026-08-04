<?php

namespace App\Http\Controllers;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StreamableAgentResponse;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

class CopilotController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $conversations = AgentConversation::orderByDesc('updated_at')
            ->get(['id', 'title', 'created_at', 'updated_at']);

        return response()->json($conversations);
    }

    public function store(): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $conversation = AgentConversation::create([
            'id' => (string) Str::uuid(),
            'title' => 'New conversation',
        ]);

        return response()->json($conversation, 201);
    }

    public function show(string $id): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $conversation = AgentConversation::with(['messages' => function ($query) {
            $query->select('id', 'conversation_id', 'role', 'content', 'created_at');
        }])->findOrFail($id);

        return response()->json($conversation);
    }

    public function destroy(string $id): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $conversation = AgentConversation::findOrFail($id);
        $conversation->messages()->delete();
        $conversation->delete();

        return response()->json(null, 204);
    }

    public function syncMessages(Request $request, string $id): JsonResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'messages' => 'required|array',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string',
        ]);

        $conversation = AgentConversation::findOrFail($id);
        $conversation->update(['title' => $validated['title']]);

        $conversation->messages()->delete();

        $now = now();
        $rows = collect($validated['messages'])->map(fn (array $m) => [
            'id' => (string) Str::uuid(),
            'conversation_id' => $id,
            'role' => $m['role'],
            'content' => $m['content'],
            'agent' => 'CrmCopilotAgent',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        AgentConversationMessage::insert($rows);

        return response()->json(null, 204);
    }

    public function chat(Request $request): StreamableAgentResponse
    {
        Gate::authorize(Permission::ContactView->value);

        $validated = $request->validate([
            'messages' => 'required|array',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string',
        ]);

        $messages = $validated['messages'];

        $lastUserMessage = collect($messages)
            ->last(fn (array $message) => $message['role'] === 'user')['content'] ?? null;

        abort_if(! $lastUserMessage, 422, 'No user message found');

        return (new CrmCopilotAgent($messages))
            ->stream($lastUserMessage)
            ->usingVercelDataProtocol();
    }
}
