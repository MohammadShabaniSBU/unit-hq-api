<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TemplateAssistTurnStatus;
use App\Enums\TemplateChannel;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateFamily;
use App\Support\Auth\Permission;
use App\Support\Communications\Exceptions\TemplateAssistTurnFrozen;
use App\Support\Templates\Assist\AssistRequestRejected;
use App\Support\Templates\Assist\TemplateAssistDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\ConversationStore;

class TemplateAssistController extends Controller
{
    public function showSession(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);
        Gate::authorize(Permission::TemplateAssistUse->value);
        $this->assertEmail($templateFamily);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:5'],
        ]);

        $session = TemplateAssistSession::query()
            ->where('template_family_id', $templateFamily->id)
            ->where('locale', $validated['locale'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('last_turn_at')
            ->orderByDesc('id')
            ->first();

        if (! $session instanceof TemplateAssistSession) {
            return $this->success(null, 'Template assist session retrieved successfully.');
        }

        return $this->success(
            $this->sessionPayload($session),
            'Template assist session retrieved successfully.',
        );
    }

    public function storeSession(Request $request, TemplateFamily $templateFamily): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);
        Gate::authorize(Permission::TemplateAssistUse->value);
        $this->assertEmail($templateFamily);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'locale' => ['required', 'string', 'max:5'],
        ]);

        $conversationId = app(ConversationStore::class)->storeConversation(
            'employee',
            $employee->id,
            $templateFamily->name.' · '.$validated['locale'],
        );

        $session = TemplateAssistSession::query()->create([
            'template_family_id' => $templateFamily->id,
            'locale' => $validated['locale'],
            'conversation_id' => $conversationId,
            'employee_id' => $employee->id,
            'last_turn_at' => now(),
        ]);

        return $this->created(
            $this->sessionPayload($session),
            'Template assist session created successfully.',
        );
    }

    public function storeTurn(Request $request, TemplateAssistSession $session): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);
        Gate::authorize(Permission::TemplateAssistUse->value);

        /** @var Employee $employee */
        $employee = $request->user();
        $this->assertOwner($session, $employee);
        $session->loadMissing('family');
        if ($session->family === null) {
            return $this->notFound();
        }
        $this->assertEmail($session->family);

        $validated = $request->validate([
            'client_message_id' => ['required', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:'.(int) config('ai.template_assist.max_prompt_chars')],
            'template_version_id' => ['required', 'integer'],
            'document' => ['required', 'array'],
            'selection' => ['sometimes', 'nullable', 'array'],
            'selection.kind' => ['required_with:selection', 'string', 'max:32'],
            'selection.id' => ['required_with:selection', 'string', 'max:64'],
        ]);

        try {
            $payload = TemplateAssistDispatcher::dispatchTurn($session, $employee, $validated);
        } catch (AssistRequestRejected $rejected) {
            return $this->reject($rejected);
        }

        return $this->accepted($payload, 'Template assist turn accepted.');
    }

    public function showTurn(Request $request, TemplateAssistTurn $turn): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);
        Gate::authorize(Permission::TemplateAssistUse->value);

        /** @var Employee $employee */
        $employee = $request->user();
        $turn->loadMissing('session');
        if ($turn->session === null) {
            return $this->notFound();
        }
        $this->assertOwner($turn->session, $employee);

        return $this->success($this->turnPayload($turn), 'Template assist turn retrieved successfully.');
    }

    public function updateStatus(Request $request, TemplateAssistTurn $turn): JsonResponse
    {
        Gate::authorize(Permission::TemplateManage->value);
        Gate::authorize(Permission::TemplateAssistUse->value);

        /** @var Employee $employee */
        $employee = $request->user();
        $turn->loadMissing('session');
        if ($turn->session === null) {
            return $this->notFound();
        }
        $this->assertOwner($turn->session, $employee);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                TemplateAssistTurnStatus::Applied->value,
                TemplateAssistTurnStatus::Conflict->value,
                TemplateAssistTurnStatus::Discarded->value,
                TemplateAssistTurnStatus::Undone->value,
            ])],
        ]);

        $turn->status = TemplateAssistTurnStatus::from($validated['status']);

        try {
            $turn->save();
        } catch (TemplateAssistTurnFrozen) {
            return $this->error('assist.status_not_allowed', [], 409);
        }

        return $this->success($this->turnPayload($turn->refresh()), 'Template assist turn status updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(TemplateAssistSession $session): array
    {
        $session->loadMissing('conversation');
        $messages = $session->conversation?->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'created_at'])
            ->map(fn ($message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at,
            ])
            ->all() ?? [];

        $turns = $session->turns()
            ->orderBy('id')
            ->get(['id', 'status', 'op_summary', 'subject_change', 'error_key', 'created_at'])
            ->map(fn (TemplateAssistTurn $turn): array => [
                'id' => $turn->id,
                'status' => $turn->status instanceof TemplateAssistTurnStatus ? $turn->status->value : (string) $turn->status,
                'op_summary' => $turn->op_summary,
                'subject_change' => $turn->subject_change,
                'error_key' => $turn->error_key,
                'created_at' => $turn->created_at,
            ])
            ->all();

        return [
            'id' => $session->id,
            'template_family_id' => $session->template_family_id,
            'locale' => $session->locale,
            'conversation_id' => $session->conversation_id,
            'messages' => $messages,
            'turns' => $turns,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function turnPayload(TemplateAssistTurn $turn): array
    {
        return [
            'status' => $turn->status instanceof TemplateAssistTurnStatus ? $turn->status->value : (string) $turn->status,
            'base_hash' => $turn->base_hash,
            'ops' => $turn->ops,
            'working_document' => $turn->working_document,
            'subject_change' => $turn->subject_change,
            'op_summary' => $turn->op_summary,
            'error_key' => $turn->error_key,
        ];
    }

    private function assertOwner(TemplateAssistSession $session, Employee $employee): void
    {
        if ((int) $session->employee_id !== (int) $employee->id) {
            abort(404);
        }
    }

    private function assertEmail(TemplateFamily $family): void
    {
        $channel = $family->channel instanceof TemplateChannel
            ? $family->channel
            : TemplateChannel::from((string) $family->channel);

        if ($channel !== TemplateChannel::Email) {
            throw ValidationException::withMessages([
                'channel' => ['assist.channel_unsupported'],
            ]);
        }
    }

    private function reject(AssistRequestRejected $rejected): JsonResponse
    {
        if ($rejected->status === 404) {
            return $this->notFound();
        }

        return $this->error($rejected->errorKey, [], $rejected->status);
    }
}
