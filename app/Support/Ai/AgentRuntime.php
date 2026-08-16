<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentHandoff;
use App\Models\AgentToolInvocation;
use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Drivers\ModelTimeoutException;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Guards\GuardrailPipeline;
use App\Support\Ai\Guards\HandoffEvaluator;
use App\Support\Ai\Guards\HandoffMatch;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\Tools\ToolResult;
use App\Support\RequestId;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Usage;
use LogicException;
use RuntimeException;

final class AgentRuntime
{
    private const CANNED_HANDOFF = 'I am connecting you with a teammate who can help with this.';

    private const CANNED_BUDGET = 'I have reached the limit for this conversation and am handing you to a teammate.';

    private const CANNED_ERROR = 'Something went wrong. I am connecting you with a teammate.';

    private const CANNED_BLOCKED = 'I need to hand this to a teammate.';

    public function __construct(
        private readonly ModelDriver $driver,
        private readonly ToolRegistry $tools,
        private readonly ToolDispatcher $dispatcher,
        private readonly AgentRegistry $agents,
        private readonly HandoffEvaluator $handoffs,
        private readonly GuardrailPipeline $guards,
    ) {}

    public function turn(
        AgentConversation $conversation,
        AgentPrincipal $principal,
        string $input,
        ?Closure $onEvent = null,
    ): AgentTurn {
        if (! filter_var(config('agents.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Customer-facing agents are disabled.');
        }

        $this->assertPrincipalMatches($conversation, $principal);

        $conversation->loadMissing('aiAgent');
        $agent = $conversation->aiAgent;
        if (! $agent->is_active || $agent->archived_at !== null) {
            throw new RuntimeException('Agent is not active.');
        }

        $definition = $this->agents->get($agent->key);
        $channel = ChannelProfile::for($conversation->channel);
        $ctx = new AgentContext($principal, $channel, $definition, $conversation, $agent);

        $match = $this->handoffs->match($conversation, $principal, $input);
        if ($match !== null) {
            return $this->shortCircuitHandoff($ctx, $principal, $input, $match, HandoffTriggerSource::Rule);
        }

        $budgetHandoff = $this->budgetHandoff($conversation, $definition, $agent);
        if ($budgetHandoff !== null) {
            return $this->shortCircuitHandoff($ctx, $principal, $input, $budgetHandoff, HandoffTriggerSource::Rule);
        }

        $priorMessages = $conversation->messages()->orderBy('sequence')->get();
        $this->persistUserMessage($conversation, $input);

        $facts = FactBag::fromCustomerMessage($input);
        $invocations = [];
        $usageTotal = new Usage;
        $toolCallCount = 0;
        $maxToolCalls = (int) config('agents.max_tool_calls_per_turn');
        $messages = $this->buildMessages($ctx, $priorMessages, $input);
        $toolObjects = $this->toolObjects($definition);
        $draft = '';
        $finishReason = 'stop';
        $lastUsage = new Usage;
        $lastLatencyMs = null;
        $draftAlreadyPersisted = false;
        $model = $agent->model;

        try {
            while (true) {
                $started = hrtime(true);
                $response = $this->driver->stream(
                    $messages,
                    $toolObjects,
                    $model,
                    $onEvent === null ? null : fn (string $delta) => $onEvent('token', ['delta' => $delta]),
                );
                $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
                $usageTotal = $usageTotal->add($response->usage);
                $finishReason = $response->finishReason;
                $lastUsage = $response->usage;
                $lastLatencyMs = $latencyMs;

                if ($response->toolCalls === []) {
                    $draft = $response->content;

                    break;
                }

                $remaining = $maxToolCalls - $toolCallCount;
                if ($remaining <= 0) {
                    $draft = $response->content;
                    if (trim($draft) === '') {
                        return $this->finishWithHandoff(
                            $ctx,
                            $facts,
                            $invocations,
                            HandoffReason::Error,
                            HandoffTriggerSource::Rule,
                            self::CANNED_ERROR,
                            ['detail' => 'max_tool_calls_per_turn'],
                        );
                    }

                    break;
                }

                $assistantMessage = $this->persistAssistantMessage(
                    $conversation,
                    $response->content,
                    $response->toolCalls,
                    $model,
                    $response->usage,
                    $latencyMs,
                    $finishReason,
                );

                $toRun = array_slice($response->toolCalls, 0, $remaining);
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $response->content,
                    'tool_calls' => $response->toolCalls,
                ];

                $escalate = null;
                foreach ($toRun as $call) {
                    $toolCallCount++;
                    if ($onEvent !== null) {
                        $onEvent('tool.started', ['tool' => $call['name'], 'id' => $call['id']]);
                    }

                    $startedTool = hrtime(true);
                    $result = $this->dispatcher->dispatch(
                        $definition,
                        $principal,
                        $call['name'],
                        $call['arguments'],
                    );
                    $durationMs = (int) ((hrtime(true) - $startedTool) / 1_000_000);

                    $invocation = $this->persistInvocation(
                        $conversation,
                        $assistantMessage,
                        $principal,
                        $call,
                        $result,
                        $durationMs,
                    );
                    $invocations[] = $invocation;
                    $this->persistToolMessage($conversation, $call, $result);

                    if ($result->status->value === 'ok') {
                        $facts->merge($result->facts);
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'content' => $this->wrapUntrusted($result->display !== '' ? $result->display : ($result->message ?? '')),
                        'tool_call_id' => $call['id'],
                        'tool_name' => $call['name'],
                        'arguments' => $call['arguments'],
                    ];

                    if ($onEvent !== null) {
                        $onEvent('tool.finished', [
                            'tool' => $call['name'],
                            'id' => $call['id'],
                            'status' => $result->status->value,
                        ]);
                    }

                    if ($result->handoffReason !== null) {
                        $escalate = $result;
                        break;
                    }
                }

                if ($escalate !== null && $escalate->handoffReason !== null) {
                    return $this->finishWithHandoff(
                        $ctx,
                        $facts,
                        $invocations,
                        $escalate->handoffReason,
                        HandoffTriggerSource::Model,
                        $escalate->display !== '' ? $escalate->display : self::CANNED_HANDOFF,
                        ['summary' => $escalate->data['summary'] ?? null],
                        $this->settleUsage($agent, $conversation, $usageTotal, $model, $toolCallCount),
                    );
                }

                if (count($response->toolCalls) > $remaining || $toolCallCount >= $maxToolCalls) {
                    $draft = $response->content;
                    $draftAlreadyPersisted = trim($draft) !== '';
                    if (trim($draft) === '') {
                        return $this->finishWithHandoff(
                            $ctx,
                            $facts,
                            $invocations,
                            HandoffReason::Error,
                            HandoffTriggerSource::Rule,
                            self::CANNED_ERROR,
                            ['detail' => 'max_tool_calls_per_turn'],
                            $this->settleUsage($agent, $conversation, $usageTotal, $model, $toolCallCount),
                        );
                    }

                    break;
                }
            }
        } catch (ModelTimeoutException) {
            return $this->finishWithHandoff(
                $ctx,
                $facts,
                $invocations,
                HandoffReason::Error,
                HandoffTriggerSource::Rule,
                self::CANNED_ERROR,
                ['detail' => 'timeout'],
                $this->settleUsage($agent, $conversation, $usageTotal, $model, $toolCallCount, AiUsageEvent::STATUS_FAILED),
            );
        }

        $verdict = $this->guards->check($draft, $facts, $ctx);
        if (! $verdict->passed) {
            $this->persistAssistantMessage(
                $conversation,
                $draft,
                [],
                $model,
                $lastUsage,
                $lastLatencyMs,
                $finishReason,
                $verdict->blockedBy,
            );

            return $this->finishWithHandoff(
                $ctx,
                $facts,
                $invocations,
                $verdict->handoffReason ?? HandoffReason::GroundingFailure,
                HandoffTriggerSource::Guardrail,
                self::CANNED_BLOCKED,
                ['blocked_by' => $verdict->blockedBy],
                $this->settleUsage($agent, $conversation, $usageTotal, $model, $toolCallCount),
                $verdict->blockedBy,
                persistAssistant: false,
            );
        }

        if (! $draftAlreadyPersisted) {
            $this->persistAssistantMessage(
                $conversation,
                $draft,
                [],
                $model,
                $lastUsage,
                $lastLatencyMs,
                $finishReason,
            );
        }

        $usage = $this->settleUsage($agent, $conversation, $usageTotal, $model, $toolCallCount);
        $this->touchConversation($conversation, ConversationState::Active);

        return new AgentTurn(
            $draft,
            $channel,
            $facts,
            $invocations,
            null,
            $usage,
            ConversationState::Active,
            null,
        );
    }

    private function assertPrincipalMatches(AgentConversation $conversation, AgentPrincipal $principal): void
    {
        $stored = $conversation->principal();

        if ($stored->contactId !== $principal->contactId
            || $stored->verification !== $principal->verification
            || $stored->audience !== $principal->audience) {
            throw new LogicException('Principal does not match conversation facts.');
        }
    }

    private function budgetHandoff(AgentConversation $conversation, AgentDefinition $definition, AiAgent $agent): ?HandoffMatch
    {
        $maxTurns = (int) ($agent->settings['max_turns'] ?? $definition->maxTurns());
        $assistantCount = $conversation->messages()
            ->where('role', AgentMessageRole::Assistant->value)
            ->count();

        if ($assistantCount >= $maxTurns) {
            return new HandoffMatch(HandoffReason::BudgetExceeded, self::CANNED_BUDGET, ['detail' => 'max_turns']);
        }

        $tokens = (int) AiUsageEvent::query()
            ->where('agent_conversation_id', $conversation->id)
            ->selectRaw('coalesce(sum(input_tokens + cached_input_tokens + output_tokens + reasoning_tokens), 0) as total')
            ->value('total');

        $budget = (int) config('agents.conversation_token_budget');
        if ($tokens >= $budget) {
            return new HandoffMatch(HandoffReason::BudgetExceeded, self::CANNED_BUDGET, ['detail' => 'conversation_token_budget']);
        }

        return null;
    }

    /**
     * @param  iterable<int, AgentConversationMessage>  $prior
     * @return list<array<string, mixed>>
     */
    private function buildMessages(AgentContext $ctx, iterable $prior, string $input): array
    {
        $messages = [
            ['role' => 'system', 'content' => $ctx->definition->systemPrompt($ctx)],
        ];

        foreach ($prior as $row) {
            if ($row->role === AgentMessageRole::User) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $this->wrapUntrusted((string) $row->content),
                ];
            } elseif ($row->role === AgentMessageRole::Assistant) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => (string) $row->content,
                    'tool_calls' => $row->tool_calls ?? [],
                ];
            } elseif ($row->role === AgentMessageRole::Tool) {
                $messages[] = [
                    'role' => 'tool',
                    'content' => $this->wrapUntrusted((string) $row->content),
                    'tool_call_id' => $row->tool_call_id,
                ];
            }
        }

        $messages[] = [
            'role' => 'user',
            'content' => $this->wrapUntrusted($input),
        ];

        return $messages;
    }

    /**
     * @return list<AgentTool>
     */
    private function toolObjects(AgentDefinition $definition): array
    {
        $objects = [];
        foreach ($definition->toolKeys() as $key) {
            if ($this->tools->has($key)) {
                $objects[] = $this->tools->get($key);
            }
        }

        return $objects;
    }

    private function wrapUntrusted(string $text): string
    {
        return "<untrusted>\n{$text}\n</untrusted>";
    }

    private function persistUserMessage(AgentConversation $conversation, string $input): AgentConversationMessage
    {
        return DB::transaction(function () use ($conversation, $input): AgentConversationMessage {
            return AgentConversationMessage::query()->create([
                'agent_conversation_id' => $conversation->id,
                'sequence' => $this->nextSequence($conversation),
                'role' => AgentMessageRole::User,
                'content' => $input,
            ]);
        });
    }

    /**
     * @param  list<array{name: string, id: string, arguments: array<string, mixed>}>  $toolCalls
     */
    private function persistAssistantMessage(
        AgentConversation $conversation,
        string $content,
        array $toolCalls,
        string $model,
        Usage $usage,
        ?int $latencyMs,
        string $finishReason,
        ?string $blockedBy = null,
    ): AgentConversationMessage {
        return DB::transaction(function () use ($conversation, $content, $toolCalls, $model, $usage, $latencyMs, $finishReason, $blockedBy): AgentConversationMessage {
            return AgentConversationMessage::query()->create([
                'agent_conversation_id' => $conversation->id,
                'sequence' => $this->nextSequence($conversation),
                'role' => AgentMessageRole::Assistant,
                'content' => $content !== '' ? $content : null,
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
                'model' => $model,
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'latency_ms' => $latencyMs,
                'finish_reason' => $finishReason,
                'blocked_by' => $blockedBy,
            ]);
        });
    }

    /**
     * @param  array{name: string, id: string, arguments: array<string, mixed>}  $call
     */
    private function persistToolMessage(AgentConversation $conversation, array $call, ToolResult $result): void
    {
        DB::transaction(function () use ($conversation, $call, $result): void {
            AgentConversationMessage::query()->create([
                'agent_conversation_id' => $conversation->id,
                'sequence' => $this->nextSequence($conversation),
                'role' => AgentMessageRole::Tool,
                'content' => $result->display !== '' ? $result->display : $result->message,
                'tool_call_id' => $call['id'],
            ]);
        });
    }

    /**
     * @param  array{name: string, id: string, arguments: array<string, mixed>}  $call
     */
    private function persistInvocation(
        AgentConversation $conversation,
        AgentConversationMessage $assistantMessage,
        AgentPrincipal $principal,
        array $call,
        ToolResult $result,
        int $durationMs,
    ): AgentToolInvocation {
        $required = $this->tools->has($call['name'])
            ? $this->tools->get($call['name'])->requiredVerification()
            : null;

        return DB::transaction(function () use ($conversation, $assistantMessage, $principal, $call, $result, $durationMs, $required): AgentToolInvocation {
            return AgentToolInvocation::query()->create([
                'agent_conversation_id' => $conversation->id,
                'agent_conversation_message_id' => $assistantMessage->id,
                'tool_key' => $call['name'],
                'arguments' => $call['arguments'],
                'result' => $result->data !== [] ? $result->data : null,
                'result_summary' => $result->display !== '' ? $result->display : $result->message,
                'status' => $result->status,
                'denied_reason' => $result->deniedReason,
                'required_verification' => $required,
                'principal_verification' => $principal->verification,
                'duration_ms' => $durationMs,
            ]);
        });
    }

    /**
     * @param  list<AgentToolInvocation>  $invocations
     * @param  array<string, mixed>|null  $detail
     */
    private function finishWithHandoff(
        AgentContext $ctx,
        FactBag $facts,
        array $invocations,
        HandoffReason $reason,
        HandoffTriggerSource $source,
        string $draft,
        ?array $detail = null,
        ?AiUsageEvent $usage = null,
        ?string $blockedBy = null,
        bool $persistAssistant = true,
    ): AgentTurn {
        $handoff = $this->writeHandoff($ctx->conversation, $reason, $source, $detail);

        if ($persistAssistant) {
            $this->persistAssistantMessage(
                $ctx->conversation,
                $draft,
                [],
                $ctx->agent->model,
                new Usage,
                null,
                'handoff',
                $blockedBy,
            );
        }

        $this->touchConversation($ctx->conversation, ConversationState::AwaitingHuman);

        return new AgentTurn(
            $draft,
            $ctx->channel,
            $facts,
            $invocations,
            $handoff,
            $usage,
            ConversationState::AwaitingHuman,
            $blockedBy,
        );
    }

    private function shortCircuitHandoff(
        AgentContext $ctx,
        AgentPrincipal $principal,
        string $input,
        HandoffMatch $match,
        HandoffTriggerSource $source,
    ): AgentTurn {
        $this->persistUserMessage($ctx->conversation, $input);

        return $this->finishWithHandoff(
            $ctx,
            FactBag::fromCustomerMessage($input),
            [],
            $match->reason,
            $source,
            $match->cannedDraft,
            $match->detail,
        );
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function writeHandoff(
        AgentConversation $conversation,
        HandoffReason $reason,
        HandoffTriggerSource $source,
        ?array $detail,
    ): AgentHandoff {
        return DB::transaction(function () use ($conversation, $reason, $source, $detail): AgentHandoff {
            return AgentHandoff::query()->create([
                'agent_conversation_id' => $conversation->id,
                'reason' => $reason,
                'trigger_source' => $source,
                'detail' => $detail,
            ]);
        });
    }

    private function settleUsage(
        AiAgent $agent,
        AgentConversation $conversation,
        Usage $usage,
        string $model,
        int $toolCalls,
        string $status = AiUsageEvent::STATUS_OK,
    ): ?AiUsageEvent {
        $callId = (string) Str::uuid7();

        $event = AiUsageEvent::reserve(
            $callId,
            null,
            null,
            'agent',
            RequestId::get(),
            $agent->id,
            $conversation->id,
        );

        $settled = AiUsageEvent::settle(
            $callId,
            $usage,
            $status,
            null,
            $model,
        );

        if ($settled !== null && $toolCalls > 0) {
            $settled->tool_calls = $toolCalls;
            $settled->save();
        }

        return $settled ?? $event;
    }

    private function touchConversation(AgentConversation $conversation, ConversationState $state): void
    {
        $conversation->state = $state;
        $conversation->last_turn_at = now();
        $conversation->save();
    }

    private function nextSequence(AgentConversation $conversation): int
    {
        return (int) $conversation->messages()->max('sequence') + 1;
    }
}
