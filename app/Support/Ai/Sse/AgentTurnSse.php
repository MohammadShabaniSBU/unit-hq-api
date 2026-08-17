<?php

declare(strict_types=1);

namespace App\Support\Ai\Sse;

use App\Enums\LogChannel;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiUsageEvent;
use App\Models\SystemEvent;
use App\Support\Ai\AgentRuntime;
use App\Support\Ai\AgentTurn;
use App\Support\Ai\AiUsageCost;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\RecordsActivity;
use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AgentTurnSse
{
    private float $lastFlushAt = 0.0;

    public function __construct(
        private readonly AgentRuntime $runtime,
    ) {}

    public function stream(AgentConversation $conversation, string $input): StreamedResponse
    {
        return new StreamedResponse(function () use ($conversation, $input): void {
            $this->prepareOutput();
            ignore_user_abort(true);

            $sequence = (int) $conversation->messages()->max('sequence') + 1;
            $this->emit('turn.started', ['sequence' => $sequence]);

            try {
                $turn = $this->runtime->turn(
                    $conversation,
                    $conversation->principal(),
                    $input,
                    $this->onEvent(),
                );
            } catch (Throwable $e) {
                SystemEvent::record('ai.turn.failed', $conversation, [
                    'error' => $e->getMessage(),
                ]);
                $this->emit('error', ['message' => 'errors.agent.turn_failed']);

                return;
            }

            $this->logTurnActivity($conversation, $turn);
            $this->emitHandoff($turn);
            $this->emitUsage($turn->usageEvents);

            $conversation->refresh();
            $message = $conversation->messages()
                ->where('role', AgentMessageRole::Assistant)
                ->orderByDesc('sequence')
                ->first();

            $this->emit('turn.completed', [
                'message_id' => $message instanceof AgentConversationMessage ? $message->id : null,
                'blocked_by' => $turn->blockedBy,
                'state' => $turn->state->value,
                'subject' => $turn->subject,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function onEvent(): Closure
    {
        return function (string $type, array $payload): void {
            $this->emit($type, $payload);
        };
    }

    /** @param  array<string, mixed>  $payload */
    private function emit(string $event, array $payload): void
    {
        $this->maybeHeartbeat();
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
        $this->flush();
    }

    private function maybeHeartbeat(): void
    {
        if ($this->lastFlushAt === 0.0) {
            return;
        }

        if ((microtime(true) - $this->lastFlushAt) >= 15.0) {
            echo ": heartbeat\n\n";
            $this->flush();
        }
    }

    private function prepareOutput(): void
    {
        if (! app()->runningUnitTests()) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);
        }

        $this->lastFlushAt = microtime(true);
    }

    private function flush(): void
    {
        if (! app()->runningUnitTests()) {
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        }

        $this->lastFlushAt = microtime(true);
    }

    private function emitHandoff(AgentTurn $turn): void
    {
        if ($turn->handoff === null) {
            return;
        }

        $this->emit('handoff', [
            'reason' => $turn->handoff->reason->value,
            'trigger_source' => $turn->handoff->trigger_source->value,
            'detail' => $turn->handoff->detail,
        ]);
    }

    /**
     * @param  list<AiUsageEvent>  $events
     */
    private function emitUsage(array $events): void
    {
        if ($events === []) {
            return;
        }

        /** @var array<string, array{input_tokens: int, output_tokens: int, estimated_cost: string, currency: string}> $byCurrency */
        $byCurrency = [];
        $uncostedInput = 0;
        $uncostedOutput = 0;

        foreach ($events as $event) {
            $cost = AiUsageCost::forEvent($event);
            if ($cost === null) {
                $uncostedInput += $event->input_tokens;
                $uncostedOutput += $event->output_tokens;

                continue;
            }

            $currency = $cost['currency'];
            if (! isset($byCurrency[$currency])) {
                $byCurrency[$currency] = [
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'estimated_cost' => '0',
                    'currency' => $currency,
                ];
            }

            $byCurrency[$currency]['input_tokens'] += $event->input_tokens;
            $byCurrency[$currency]['output_tokens'] += $event->output_tokens;
            $byCurrency[$currency]['estimated_cost'] = bcadd(
                $byCurrency[$currency]['estimated_cost'],
                $cost['estimated_cost'],
                6,
            );
        }

        foreach ($byCurrency as $payload) {
            $this->emit('usage', $payload);
        }

        if ($byCurrency === []) {
            $this->emit('usage', [
                'input_tokens' => $uncostedInput,
                'output_tokens' => $uncostedOutput,
                'estimated_cost' => null,
                'currency' => null,
            ]);
        }
    }

    private function logTurnActivity(AgentConversation $conversation, AgentTurn $turn): void
    {
        if ($turn->handoff !== null) {
            RecordsActivity::log(
                LogChannel::Ai,
                'agent.handoff',
                $conversation,
                [
                    'reason' => $turn->handoff->reason->value,
                    'trigger_source' => $turn->handoff->trigger_source->value,
                ],
            );
        }

        if ($turn->blockedBy !== null) {
            RecordsActivity::log(
                LogChannel::Ai,
                'agent.guardrail.blocked',
                $conversation,
                [
                    'guard' => $turn->blockedBy,
                    'blocked_by' => $turn->blockedBy,
                ],
            );
        }
    }
}
