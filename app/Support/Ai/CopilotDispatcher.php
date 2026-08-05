<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Ai\Agents\CrmCopilotAgent;
use App\Models\CopilotConversation;
use App\Models\Employee;
use App\Support\RequestId;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Jobs\BroadcastAgent;

/**
 * Dispatches copilot turns onto the ai queue and returns the 202 payload.
 *
 * After an approved tool runs, the SDK stores the tool result before asking
 * the model to continue. If that generation then fails, resume with a normal
 * text prompt — never by resubmitting the same approval decisions.
 */
final class CopilotDispatcher
{
    private const IDEMPOTENCY_TTL_SECONDS = 600;

    /**
     * @param  array{message: string, client_message_id: string}  $validated
     * @return array{call_id: string, conversation_id: string, channel: string}
     */
    public static function dispatchTurn(
        CopilotConversation $conversation,
        Employee $employee,
        array $validated,
    ): array {
        $cacheKey = self::idempotencyKey($conversation->id, $validated['client_message_id']);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = self::dispatchPrompt(
            $conversation,
            $employee,
            $validated['message'],
        );

        Cache::put($cacheKey, $payload, self::IDEMPOTENCY_TTL_SECONDS);

        return $payload;
    }

    /**
     * Resume a paused turn with tool approval decisions.
     *
     * @return array{call_id: string, conversation_id: string, channel: string}
     */
    public static function dispatchDecisions(
        CopilotConversation $conversation,
        Employee $employee,
        Decisions $decisions,
    ): array {
        return self::dispatchPrompt($conversation, $employee, $decisions);
    }

    /**
     * @return array{call_id: string, conversation_id: string, channel: string}
     */
    private static function dispatchPrompt(
        CopilotConversation $conversation,
        Employee $employee,
        Decisions|string $prompt,
    ): array {
        $channelName = "copilot.{$conversation->id}";
        $channel = new PrivateChannel($channelName);

        // Stamp correlation before the PendingDispatch destructs and pushes the job.
        $callId = (string) Str::uuid7();
        Context::add([
            'ai_call_id' => $callId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'copilot',
            'conversation_id' => $conversation->id,
            'request_id' => RequestId::get(),
        ]);

        $queued = (new CrmCopilotAgent($employee))
            ->continue($conversation->id, as: $employee)
            ->broadcastOnQueue($prompt, $channel);

        $queued->onQueue('ai');

        $job = $queued->getJob();
        if ($job instanceof BroadcastAgent) {
            $callId = $job->invocationId;
            Context::add('ai_call_id', $callId);
        }

        return [
            'call_id' => $callId,
            'conversation_id' => $conversation->id,
            'channel' => "private-{$channelName}",
        ];
    }

    private static function idempotencyKey(string $conversationId, string $clientMessageId): string
    {
        return "copilot:dispatch:{$conversationId}:{$clientMessageId}";
    }
}
