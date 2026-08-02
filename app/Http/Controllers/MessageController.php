<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\ThreadMover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

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
}
