<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CallWrapup;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\CallRecordingProxy;
use App\Support\Communications\Channel;
use App\Support\Communications\ThreadMover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function moveThread(Request $request, Message $message): JsonResponse
    {
        $validated = $request->validate([
            'message_thread_id' => ['sometimes', 'nullable', 'integer', 'exists:message_threads,id'],
            'new_thread' => ['sometimes', 'boolean'],
        ]);

        $newThread = (bool) ($validated['new_thread'] ?? false);
        $targetId = isset($validated['message_thread_id']) ? (int) $validated['message_thread_id'] : null;

        if ($newThread && $targetId !== null) {
            return $this->error('Provide either message_thread_id or new_thread, not both.', statusCode: 422);
        }

        if (! $newThread && $targetId === null) {
            return $this->error('Provide message_thread_id or new_thread.', statusCode: 422);
        }

        try {
            if ($newThread) {
                $moved = ThreadMover::moveToNewThread($message, $request->user());
            } else {
                $target = MessageThread::query()->findOrFail($targetId);
                $moved = ThreadMover::move($message, $target, $request->user());
            }
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), statusCode: 422);
        }

        return $this->success([
            'message_id' => $moved->id,
            'message_thread_id' => $moved->message_thread_id,
            'from_thread_id' => $moved->threading_evidence['from_thread_id'] ?? null,
        ], 'Message moved.');
    }

    public function showWrapup(Message $message): JsonResponse
    {
        if (! $this->isCallMessage($message)) {
            return $this->error('Wrap-up is only available on call messages.', statusCode: 422);
        }

        $wrapup = $message->wrapup;

        return $this->success(
            $wrapup !== null ? $this->mapWrapup($wrapup) : null,
            'Call wrap-up retrieved successfully.',
        );
    }

    public function upsertWrapup(Request $request, Message $message): JsonResponse
    {
        if (! $this->isCallMessage($message)) {
            return $this->error('Wrap-up is only available on call messages.', statusCode: 422);
        }

        $dispositions = config('communications.call_dispositions', []);

        $validated = $request->validate([
            'disposition' => ['nullable', 'string', Rule::in($dispositions)],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        /** @var Employee $employee */
        $employee = $request->user();

        $attributes = [
            'employee_id' => $employee->id,
            'disposition' => array_key_exists('disposition', $validated)
                ? $validated['disposition']
                : null,
        ];
        if (array_key_exists('note', $validated)) {
            $attributes['note'] = $validated['note'];
        }

        $existing = CallWrapup::query()->where('message_id', $message->id)->first();
        if ($existing !== null) {
            if (! array_key_exists('disposition', $validated)) {
                unset($attributes['disposition']);
            }
            $existing->fill($attributes)->save();
            $wrapup = $existing;
        } else {
            $wrapup = CallWrapup::query()->create([
                'message_id' => $message->id,
                'disposition' => $attributes['disposition'] ?? null,
                'note' => $attributes['note'] ?? null,
                'employee_id' => $employee->id,
            ]);
        }

        return $this->success($this->mapWrapup($wrapup->fresh() ?? $wrapup), 'Call wrap-up saved.');
    }

    public function recording(Message $message): StreamedResponse|JsonResponse
    {
        if (! $this->isCallMessage($message)) {
            return $this->notFound('Recording unavailable');
        }

        $result = CallRecordingProxy::stream($message);
        if (is_array($result)) {
            return $this->notFound($result['error']);
        }

        return $result;
    }

    private function isCallMessage(Message $message): bool
    {
        $message->loadMissing('thread');

        return $message->thread?->channel === Channel::Call;
    }

    /**
     * @return array{disposition: string|null, note: string|null, employee_id: int, updated_at: string|null, created_at: string|null}
     */
    private function mapWrapup(CallWrapup $wrapup): array
    {
        return [
            'disposition' => $wrapup->disposition,
            'note' => $wrapup->note,
            'employee_id' => (int) $wrapup->employee_id,
            'updated_at' => $wrapup->updated_at?->toIso8601String(),
            'created_at' => $wrapup->created_at?->toIso8601String(),
        ];
    }
}
