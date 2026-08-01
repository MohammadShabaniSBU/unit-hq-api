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
            'message_thread_id' => ['required', 'integer', 'exists:message_threads,id'],
        ]);

        $target = MessageThread::query()->findOrFail($validated['message_thread_id']);

        try {
            $moved = ThreadMover::move($message, $target, $request->user());
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
