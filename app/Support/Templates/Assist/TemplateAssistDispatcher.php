<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

use App\Ai\Agents\TemplateAssistantAgent;
use App\Enums\TemplateAssistTurnStatus;
use App\Models\Employee;
use App\Models\TemplateAssistSession;
use App\Models\TemplateAssistTurn;
use App\Models\TemplateVersion;
use App\Support\Communications\EmailBlockDocument;
use App\Support\RequestId;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Ai\Jobs\BroadcastAgent;

/**
 * Queues one template-assistant turn and returns the 202 payload.
 *
 * The daily cap uses the UTC day. Site-local midnight does not apply.
 */
final class TemplateAssistDispatcher
{
    /**
     * @param  array{
     *     message: string,
     *     client_message_id: string,
     *     template_version_id: int,
     *     document: array<string, mixed>,
     *     selection?: array{kind: string, id: string}|null
     * }  $validated
     * @return array{call_id: string, turn_id: int, session_id: int, channel: string}
     */
    public static function dispatchTurn(
        TemplateAssistSession $session,
        Employee $employee,
        array $validated,
    ): array {
        $existing = self::findTurn($session, $validated['client_message_id']);
        if ($existing instanceof TemplateAssistTurn) {
            return self::payload($existing, $session);
        }

        self::assertUnderDailyLimit($employee);

        $document = EmailBlockDocument::validateForWrite($validated['document']);
        self::assertCurrentDraft($session, (int) $validated['template_version_id']);

        $callId = (string) Str::uuid7();

        try {
            $turn = TemplateAssistTurn::query()->create([
                'session_id' => $session->id,
                'call_id' => $callId,
                'client_message_id' => $validated['client_message_id'],
                'template_version_id' => (int) $validated['template_version_id'],
                'selection' => $validated['selection'] ?? null,
                'base_document' => $document,
                'base_hash' => CanonicalDocument::hash($document),
                'working_document' => $document,
                'ops' => [],
                'op_summary' => [
                    'added' => 0,
                    'edited' => 0,
                    'removed' => 0,
                    'moved' => 0,
                    'sections_added' => 0,
                ],
                'status' => TemplateAssistTurnStatus::Running,
                'prompt_version' => (string) config('ai.template_assist.prompt_version'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $raced = self::findTurn($session, $validated['client_message_id']);
            if ($raced instanceof TemplateAssistTurn) {
                return self::payload($raced, $session);
            }

            throw $exception;
        }

        $session->last_turn_at = now();
        $session->save();

        $channelName = "template-assist.{$session->id}";

        Context::add([
            'ai_call_id' => $callId,
            'employee_id' => $employee->id,
            'ai_purpose' => 'template_assist',
            'conversation_id' => $session->conversation_id,
            'request_id' => RequestId::get(),
        ]);

        $queued = (new TemplateAssistantAgent($employee, $turn->id))
            ->continue($session->conversation_id, as: $employee)
            ->broadcastOnQueue($validated['message'], new PrivateChannel($channelName));

        $queued->onQueue('ai');

        $job = $queued->getJob();
        if ($job instanceof BroadcastAgent) {
            $callId = $job->invocationId;
            Context::add('ai_call_id', $callId);
            if ($turn->call_id !== $callId) {
                $turn->call_id = $callId;
                $turn->save();
            }
        }

        return self::payload($turn, $session);
    }

    private static function assertUnderDailyLimit(Employee $employee): void
    {
        $limit = (int) config('ai.template_assist.daily_turn_limit');
        $start = Carbon::now('UTC')->startOfDay();

        $count = TemplateAssistTurn::query()
            ->where('created_at', '>=', $start)
            ->whereHas('session', function ($query) use ($employee): void {
                $query->where('employee_id', $employee->id);
            })
            ->count();

        if ($count >= $limit) {
            throw AssistRequestRejected::dailyLimit();
        }
    }

    private static function assertCurrentDraft(TemplateAssistSession $session, int $versionId): void
    {
        $session->loadMissing('family.draft');
        $family = $session->family;
        if ($family === null) {
            throw AssistRequestRejected::notFound();
        }

        $version = TemplateVersion::query()->find($versionId);
        if (! $version instanceof TemplateVersion || (int) $version->template_family_id !== (int) $family->id) {
            throw AssistRequestRejected::notFound();
        }

        $draftId = $family->draft?->id;
        if ($draftId === null || (int) $draftId !== $versionId) {
            throw AssistRequestRejected::draftChanged();
        }
    }

    private static function findTurn(TemplateAssistSession $session, string $clientMessageId): ?TemplateAssistTurn
    {
        $turn = TemplateAssistTurn::query()
            ->where('session_id', $session->id)
            ->where('client_message_id', $clientMessageId)
            ->first();

        return $turn instanceof TemplateAssistTurn ? $turn : null;
    }

    /**
     * @return array{call_id: string, turn_id: int, session_id: int, channel: string}
     */
    private static function payload(TemplateAssistTurn $turn, TemplateAssistSession $session): array
    {
        return [
            'call_id' => $turn->call_id,
            'turn_id' => $turn->id,
            'session_id' => $session->id,
            'channel' => 'private-template-assist.'.$session->id,
        ];
    }
}
