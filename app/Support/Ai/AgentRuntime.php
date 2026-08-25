<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentGuardrailEvent;
use App\Models\AgentHandoff;
use App\Models\AgentToolInvocation;
use App\Models\AiAgent;
use App\Models\AiUsageEvent;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Support\Ai\Agents\AgentDefinition;
use App\Support\Ai\Agents\AgentRegistry;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Drivers\ModelResponse;
use App\Support\Ai\Drivers\ModelTimeoutException;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Guards\DisclosureGuard;
use App\Support\Ai\Guards\GuardrailPipeline;
use App\Support\Ai\Guards\GuardrailVerdict;
use App\Support\Ai\Guards\HandoffMatch;
use App\Support\Ai\Guards\InboundGuardPipeline;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\ArgumentBag;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\RefsRenderer;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolError;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Ai\Trace\TraceCursor;
use App\Support\RequestId;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Usage;
use LogicException;
use RuntimeException;
use Throwable;

final class AgentRuntime
{
    public function __construct(
        private readonly ModelDriver $driver,
        private readonly ToolRegistry $tools,
        private readonly ToolDispatcher $dispatcher,
        private readonly AgentRegistry $agents,
        private readonly InboundGuardPipeline $inbound,
        private readonly GuardrailPipeline $guards,
        private readonly AiProviderRegistry $providers,
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

        $conversation->loadMissing(['aiAgent.writePolicies']);
        $agent = $conversation->aiAgent;
        if (! $agent->is_active || $agent->archived_at !== null) {
            throw new RuntimeException('Agent is not active.');
        }

        $definition = $this->agents->get($agent->key);
        $channel = ChannelProfile::for($conversation->channel);
        $ctx = new AgentContext($principal, $channel, $definition, $conversation, $agent);

        $match = $this->inbound->evaluate($conversation, $principal, $input, $definition, $agent);
        if ($match !== null) {
            return $this->shortCircuitHandoff($ctx, $principal, $input, $match, HandoffTriggerSource::Rule);
        }

        $priorMessages = $conversation->messages()->orderBy('sequence')->get();
        $userMessage = $this->persistUserMessage($conversation, $input);
        $cursor = TraceCursor::start($ctx, $userMessage->id);
        $this->dispatcher->beginTurn();

        $site = $this->siteFor($principal);
        $facts = FactBag::fromCustomerMessage($input, $site);
        /** @var list<ForbiddenClaimKey> $licensedClaims */
        $licensedClaims = [];
        $invocations = [];
        $usageEvents = [];
        $usageTotal = new Usage;
        $toolCallCount = 0;
        $maxToolCalls = (int) config('agents.max_tool_calls_per_turn');
        $maxToolRetries = (int) config('agents.max_tool_retries');
        /** @var array<string, int> $consecutiveFailures */
        $consecutiveFailures = [];
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
                $response = $this->streamMetered(
                    $agent,
                    $conversation,
                    $messages,
                    $toolObjects,
                    $model,
                    $onEvent,
                    $usageEvents,
                    $cursor,
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
                            CannedReply::Error,
                            ['detail' => 'max_tool_calls_per_turn'],
                            $usageEvents,
                            cursor: $cursor,
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
                    facts: $facts,
                    principal: $principal,
                );
                $this->bindOpenTraceRows($cursor, $assistantMessage->id, $usageEvents);

                $toRun = array_slice($response->toolCalls, 0, $remaining);
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $response->content,
                    'tool_calls' => $response->toolCalls,
                ];

                $escalate = null;
                $retryExhausted = null;
                foreach ($toRun as $call) {
                    $call['arguments'] = ArgumentBag::normalise($call['arguments'] ?? []);
                    $toolCallCount++;
                    $toolSeq = $cursor->allocateSeq();
                    if ($onEvent !== null) {
                        $onEvent('tool.started', [
                            ...$cursor->envelope($assistantMessage->id, $toolSeq),
                            'tool_key' => $call['name'],
                            'arguments' => ArgumentBag::jsonReady($call['arguments']),
                        ]);
                    }

                    $startedTool = hrtime(true);
                    $result = $this->dispatcher->dispatch(
                        $definition,
                        $principal,
                        $call['name'],
                        $call['arguments'],
                        $ctx,
                    );
                    $durationMs = (int) ((hrtime(true) - $startedTool) / 1_000_000);

                    if ($this->shouldRefuseErrorEscalate($call, $consecutiveFailures, $maxToolRetries)) {
                        $result = $this->refuseErrorEscalate($consecutiveFailures);
                    }

                    $invocation = $this->persistInvocation(
                        $conversation,
                        $assistantMessage,
                        $principal,
                        $call,
                        $result,
                        $durationMs,
                        $cursor,
                        $toolSeq,
                    );
                    $invocations[] = $invocation;

                    $pendingActionId = null;
                    if ($result->deniedReason === ToolDeniedReason::RequiresApproval) {
                        try {
                            $invocation->loadMissing('conversation');
                            $pending = app(PendingActionRecorder::class)->record($invocation, $result);
                            $pendingActionId = $pending->id;
                        } catch (Throwable $e) {
                            report($e);
                            $result = new ToolResult(
                                ToolInvocationStatus::Error,
                                [],
                                CannedReply::Error,
                                new FactBag,
                                message: CannedReply::Error,
                                handoffReason: HandoffReason::Error,
                            );
                            $this->persistToolMessage($conversation, $call, $result);
                            $escalate = $result;
                            break;
                        }
                    }

                    $this->persistToolMessage($conversation, $call, $result);

                    $promoted = PrincipalPromotion::afterToolResult(
                        $conversation,
                        $principal,
                        $call['name'],
                        $result,
                        $ctx,
                    );
                    if ($promoted !== null) {
                        $principal = $promoted;
                        $ctx = $ctx->withPrincipal($promoted);
                    }

                    if ($result->status === ToolInvocationStatus::Ok) {
                        $facts->merge($result->facts);
                        $this->dispatcher->rememberEntities($result->entities);
                        // Licences are not persisted (unlike fact_keys). A reconstructed
                        // replay result therefore has empty licensedClaims by construction;
                        // the !$replayed check is defence so a later "optimisation" that
                        // stores licensed_claims next to fact_keys cannot reintroduce the
                        // cross-turn leak invariant 63 exists to prevent.
                        if (! $result->replayed) {
                            foreach ($result->licensedClaims as $claim) {
                                $licensedClaims[] = $claim;
                            }
                        }
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'content' => $this->wrapUntrusted($result->modelText()),
                        'tool_call_id' => $call['id'],
                        'tool_name' => $call['name'],
                        'arguments' => ArgumentBag::jsonReady($call['arguments']),
                    ];

                    if ($onEvent !== null) {
                        $error = $result->error;
                        $onEvent('tool.finished', [
                            ...$cursor->envelope($assistantMessage->id, $toolSeq, $invocation->created_at),
                            'tool_key' => $call['name'],
                            'status' => $result->status->value,
                            'denied_reason' => $result->deniedReason?->value,
                            'duration_ms' => $durationMs,
                            'result_summary' => $result->display !== '' ? $result->display : $result->message,
                            'invocation_id' => $invocation->id,
                            'pending_action_id' => $pendingActionId,
                            'replayed' => $result->replayed,
                            'entities' => array_map(
                                static fn ($ref): array => $ref->toArray(),
                                $result->entities,
                            ),
                            'error_code' => $error?->errorCode->value,
                            'recovery' => $error?->recovery,
                        ]);
                    }

                    if ($call['name'] !== 'agent.escalate') {
                        if ($result->status === ToolInvocationStatus::Ok) {
                            unset($consecutiveFailures[$call['name']]);
                        } elseif ($this->isRetryableFailure($result)) {
                            $consecutiveFailures[$call['name']] = ($consecutiveFailures[$call['name']] ?? 0) + 1;
                            if ($consecutiveFailures[$call['name']] >= $maxToolRetries) {
                                $retryExhausted = [
                                    'detail' => 'tool_retry_exhausted',
                                    'tool' => $call['name'],
                                    'error_code' => $result->error?->errorCode->value,
                                ];
                                break;
                            }
                        }
                    }

                    if ($result->handoffReason !== null) {
                        $escalate = $result;
                        break;
                    }
                }

                if ($usageEvents !== []) {
                    $metered = $usageEvents[array_key_last($usageEvents)];
                    $metered->tool_calls = count($toRun);
                    $metered->save();
                }

                if ($retryExhausted !== null) {
                    return $this->finishWithHandoff(
                        $ctx,
                        $facts,
                        $invocations,
                        HandoffReason::Error,
                        HandoffTriggerSource::Rule,
                        CannedReply::Error,
                        $retryExhausted,
                        $usageEvents,
                        cursor: $cursor,
                    );
                }

                if ($escalate !== null && $escalate->handoffReason !== null) {
                    return $this->finishWithHandoff(
                        $ctx,
                        $facts,
                        $invocations,
                        $escalate->handoffReason,
                        HandoffTriggerSource::Model,
                        $escalate->display !== '' ? $escalate->display : CannedReply::Handoff,
                        ['summary' => $escalate->data['summary'] ?? null],
                        $usageEvents,
                        cursor: $cursor,
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
                            CannedReply::Error,
                            ['detail' => 'max_tool_calls_per_turn'],
                            $usageEvents,
                            cursor: $cursor,
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
                CannedReply::Error,
                ['detail' => 'timeout'],
                $usageEvents,
                cursor: $cursor,
            );
        }

        $verdict = $this->applyOutboundGuards(
            $draft,
            $facts,
            $ctx->withLicensedClaims($licensedClaims),
            $messages,
            $toolObjects,
            $model,
            $onEvent,
            $usageTotal,
            $lastUsage,
            $lastLatencyMs,
            $finishReason,
            $usageEvents,
            $cursor,
        );
        $draft = $verdict['draft'];
        $usageTotal = $verdict['usage'];
        $lastUsage = $verdict['lastUsage'];
        $lastLatencyMs = $verdict['lastLatencyMs'];
        $finishReason = $verdict['finishReason'];
        $outbound = $verdict['verdict'];

        if (! $outbound->passed) {
            $blockedMessage = $this->persistAssistantMessage(
                $conversation,
                $draft,
                [],
                $model,
                $lastUsage,
                $lastLatencyMs,
                $finishReason,
                $outbound->blockedBy,
                $facts,
                $principal,
                $outbound->subject,
            );
            $this->bindOpenTraceRows($cursor, $blockedMessage->id, $usageEvents);

            return $this->finishWithHandoff(
                $ctx,
                $facts,
                $invocations,
                $outbound->handoffReason ?? HandoffReason::GroundingFailure,
                HandoffTriggerSource::Guardrail,
                CannedReply::Blocked,
                array_filter([
                    'blocked_by' => $outbound->blockedBy,
                    ...($outbound->detail ?? []),
                ], fn (mixed $value): bool => $value !== null),
                $usageEvents,
                $outbound->blockedBy,
                persistAssistant: false,
                guardrailEvents: $outbound->events,
                cursor: $cursor,
                assistantMessageId: $blockedMessage->id,
            );
        }

        if ($outbound->mutatedDraft !== null) {
            $draft = $outbound->mutatedDraft;
        }

        if (! $draftAlreadyPersisted) {
            $finalMessage = $this->persistAssistantMessage(
                $conversation,
                $draft,
                [],
                $model,
                $lastUsage,
                $lastLatencyMs,
                $finishReason,
                facts: $facts,
                principal: $principal,
                subject: $outbound->subject,
            );
            $this->bindOpenTraceRows($cursor, $finalMessage->id, $usageEvents);
        } else {
            $latest = $conversation->messages()
                ->where('role', AgentMessageRole::Assistant)
                ->orderByDesc('sequence')
                ->first();
            if ($latest instanceof AgentConversationMessage) {
                $this->bindOpenTraceRows($cursor, $latest->id, $usageEvents);
            }
        }

        $this->touchConversation($conversation, ConversationState::Active);

        return new AgentTurn(
            $draft,
            $channel,
            $facts,
            $invocations,
            null,
            $usageEvents,
            ConversationState::Active,
            null,
            $outbound->subject,
            $outbound->events,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<AgentTool>  $toolObjects
     * @param  list<AiUsageEvent>  $usageEvents
     * @return array{
     *     draft: string,
     *     verdict: GuardrailVerdict,
     *     usage: Usage,
     *     lastUsage: Usage,
     *     lastLatencyMs: int|null,
     *     finishReason: string
     * }
     */
    private function applyOutboundGuards(
        string $draft,
        FactBag $facts,
        AgentContext $ctx,
        array $messages,
        array $toolObjects,
        string $model,
        ?Closure $onEvent,
        Usage $usageTotal,
        Usage $lastUsage,
        ?int $lastLatencyMs,
        string $finishReason,
        array &$usageEvents,
        TraceCursor $cursor,
    ): array {
        $verdict = $this->guards->check($draft, $facts, $ctx);
        $this->persistAndEmitGuardrails($onEvent, $verdict, $cursor);
        $accumulated = $verdict->events;
        $maxRedrafts = (int) config('agents.channel.sms.max_redraft_attempts', 2);
        $attempts = 0;

        while ($verdict->retry !== null && $attempts < $maxRedrafts) {
            $attempts++;
            $messages[] = ['role' => 'assistant', 'content' => $draft];
            $messages[] = ['role' => 'system', 'content' => $verdict->retry];

            try {
                $started = hrtime(true);
                $response = $this->streamMetered(
                    $ctx->agent,
                    $ctx->conversation,
                    $messages,
                    $toolObjects,
                    $model,
                    $onEvent,
                    $usageEvents,
                    $cursor,
                );
                $lastLatencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
                $usageTotal = $usageTotal->add($response->usage);
                $lastUsage = $response->usage;
                $finishReason = $response->finishReason;
            } catch (ModelTimeoutException) {
                $verdict = GuardrailVerdict::block(
                    $verdict->blockedBy ?? 'channel',
                    HandoffReason::Error,
                    ['detail' => 'timeout'],
                    $accumulated,
                );

                return [
                    'draft' => $draft,
                    'verdict' => $verdict,
                    'usage' => $usageTotal,
                    'lastUsage' => $lastUsage,
                    'lastLatencyMs' => $lastLatencyMs,
                    'finishReason' => $finishReason,
                ];
            }

            if ($response->toolCalls !== []) {
                $verdict = $this->withEvents($this->retryAsBlock($verdict), $accumulated);
                break;
            }

            $draft = $response->content;
            $verdict = $this->guards->check($draft, $facts, $ctx);
            $this->persistAndEmitGuardrails($onEvent, $verdict, $cursor);
            $accumulated = array_merge($accumulated, $verdict->events);
            $verdict = $this->withEvents($verdict, $accumulated);

            if (! $verdict->passed && $verdict->retry === null) {
                break;
            }
        }

        if ($verdict->retry !== null) {
            $verdict = $this->withEvents($this->retryAsBlock($verdict), $accumulated);
        }

        return [
            'draft' => $draft,
            'verdict' => $verdict,
            'usage' => $usageTotal,
            'lastUsage' => $lastUsage,
            'lastLatencyMs' => $lastLatencyMs,
            'finishReason' => $finishReason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function withEvents(GuardrailVerdict $verdict, array $events): GuardrailVerdict
    {
        return new GuardrailVerdict(
            $verdict->passed,
            $verdict->blockedBy,
            $verdict->handoffReason,
            $verdict->detail,
            $verdict->mutatedDraft,
            $verdict->retry,
            $events,
            $verdict->subject,
        );
    }

    private function retryAsBlock(GuardrailVerdict $verdict): GuardrailVerdict
    {
        return GuardrailVerdict::block(
            $verdict->blockedBy ?? 'channel',
            $verdict->handoffReason ?? HandoffReason::Error,
            $verdict->detail,
            $verdict->events,
        );
    }

    private function persistAndEmitGuardrails(?Closure $onEvent, GuardrailVerdict $verdict, TraceCursor $cursor, ?int $messageId = null): void
    {
        foreach ($verdict->events as $event) {
            $seq = $cursor->allocateSeq();
            $detail = array_diff_key($event, ['guard' => true, 'verdict' => true]);
            $detail = $detail !== [] ? $detail : null;

            $row = AgentGuardrailEvent::query()->create([
                'agent_conversation_id' => $cursor->conversationId,
                'agent_conversation_message_id' => $messageId,
                'turn' => $cursor->turn,
                'seq' => $seq,
                'guard' => (string) ($event['guard'] ?? 'unknown'),
                'verdict' => (string) ($event['verdict'] ?? 'pass'),
                'detail' => $detail,
                'model' => $cursor->model,
                'prompt_version' => $cursor->promptVersion,
            ]);

            if ($onEvent !== null) {
                $onEvent('guardrail', [
                    ...$cursor->envelope($messageId, $seq, $row->created_at),
                    ...$event,
                ]);
            }
        }
    }

    private function persistInboundGuardrail(TraceCursor $cursor, HandoffMatch $match): void
    {
        AgentGuardrailEvent::query()->create([
            'agent_conversation_id' => $cursor->conversationId,
            'agent_conversation_message_id' => $cursor->userMessageId,
            'turn' => $cursor->turn,
            'seq' => $cursor->allocateSeq(),
            'guard' => $match->guard,
            'verdict' => 'handoff',
            'detail' => $match->detail,
            'model' => $cursor->model,
            'prompt_version' => $cursor->promptVersion,
        ]);
    }

    /**
     * @param  list<AiUsageEvent>  $usageEvents
     */
    private function bindOpenTraceRows(TraceCursor $cursor, int $messageId, array &$usageEvents = []): void
    {
        AgentGuardrailEvent::query()
            ->where('agent_conversation_id', $cursor->conversationId)
            ->where('turn', $cursor->turn)
            ->whereNull('agent_conversation_message_id')
            ->update(['agent_conversation_message_id' => $messageId]);

        AiUsageEvent::query()
            ->where('agent_conversation_id', $cursor->conversationId)
            ->where('turn', $cursor->turn)
            ->whereNull('agent_conversation_message_id')
            ->update(['agent_conversation_message_id' => $messageId]);

        foreach ($usageEvents as $event) {
            if ($event->agent_conversation_message_id === null && (int) $event->turn === $cursor->turn) {
                $event->agent_conversation_message_id = $messageId;
            }
        }
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

    /**
     * @param  iterable<int, AgentConversationMessage>  $prior
     * @return list<array<string, mixed>>
     */
    private function buildMessages(AgentContext $ctx, iterable $prior, string $input): array
    {
        $messages = [
            ['role' => 'system', 'content' => $ctx->definition->systemPrompt($ctx)],
        ];

        $invocations = $this->invocationsByCall($ctx->conversation);
        $assistantRow = null;

        foreach ($prior as $row) {
            if ($row->role === AgentMessageRole::User) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $this->wrapUntrusted((string) $row->content),
                ];
            } elseif ($row->role === AgentMessageRole::Assistant) {
                $assistantRow = $row;
                $messages[] = [
                    'role' => 'assistant',
                    'content' => (string) $row->content,
                    'tool_calls' => $row->tool_calls ?? [],
                ];
            } elseif ($row->role === AgentMessageRole::Tool) {
                $key = $assistantRow !== null && $row->tool_call_id !== null
                    ? $assistantRow->id.':'.$row->tool_call_id
                    : null;

                $messages[] = [
                    'role' => 'tool',
                    'content' => $this->wrapUntrusted($this->rehydratedToolText(
                        (string) $row->content,
                        $key !== null ? ($invocations[$key] ?? null) : null,
                    )),
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
     * Keyed by "{assistant message id}:{tool call id}". Fake and cassette drivers
     * reuse call ids across turns, so the assistant message has to scope the match.
     *
     * @return array<string, AgentToolInvocation>
     */
    private function invocationsByCall(AgentConversation $conversation): array
    {
        $byCall = [];

        foreach ($conversation->toolInvocations()
            ->whereNotNull('tool_call_id')
            ->whereNotNull('agent_conversation_message_id')
            ->get() as $invocation) {
            $byCall[$invocation->agent_conversation_message_id.':'.$invocation->tool_call_id] = $invocation;
        }

        return $byCall;
    }

    /**
     * Tool messages persist `display` only, so the refs line is projected back at
     * read time from the invocation trace. Without it the model loses the ids on
     * the very turn it has to pass them.
     */
    private function rehydratedToolText(string $content, ?AgentToolInvocation $invocation): string
    {
        if ($invocation === null) {
            return $content;
        }

        $blob = is_array($invocation->result) ? $invocation->result : null;
        $error = ToolResult::errorFromTrace($blob);

        $line = $invocation->status === ToolInvocationStatus::Ok
            ? RefsRenderer::render(ToolResult::entitiesFromTrace($blob))
            : RefsRenderer::render($error?->candidates ?? [], 'Candidates');
        $recovery = $error?->recoveryLine() ?? '';

        $parts = [$content];
        if ($line !== '') {
            $parts[] = $line;
        }
        if ($recovery !== '') {
            $parts[] = $recovery;
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $call
     * @param  array<string, int>  $consecutiveFailures
     */
    private function shouldRefuseErrorEscalate(array $call, array $consecutiveFailures, int $maxToolRetries): bool
    {
        if (($call['name'] ?? '') !== 'agent.escalate') {
            return false;
        }
        if ((string) ($call['arguments']['reason'] ?? '') !== HandoffReason::Error->value) {
            return false;
        }

        $maxFailures = $consecutiveFailures === [] ? 0 : max($consecutiveFailures);

        return $maxFailures < $maxToolRetries;
    }

    /**
     * @param  array<string, int>  $consecutiveFailures
     */
    private function refuseErrorEscalate(array $consecutiveFailures): ToolResult
    {
        if ($consecutiveFailures === []) {
            return ToolResult::fail(ToolError::invalidArguments(
                'No tool has returned an error in this turn.',
                ['hint' => 'no tool has returned an error in this turn; use a different reason'],
            ));
        }

        $lastFailedTool = (string) array_key_last($consecutiveFailures);

        return ToolResult::fail(ToolError::invalidArguments(
            "Retry {$lastFailedTool} before escalating.",
            [
                'tool' => $lastFailedTool,
                'hint' => "retry {$lastFailedTool}; a retry is still available",
            ],
        ));
    }

    private function isRetryableFailure(ToolResult $result): bool
    {
        $code = $result->error?->errorCode;

        return $code === ToolErrorCode::InvalidArguments
            || $code === ToolErrorCode::NotFound
            || $code === ToolErrorCode::SiteUnresolved
            || $code === ToolErrorCode::UnlicensedArgument
            || $code === ToolErrorCode::PriceSuperseded;
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
        ?FactBag $facts = null,
        ?AgentPrincipal $principal = null,
        ?string $subject = null,
    ): AgentConversationMessage {
        return DB::transaction(function () use ($conversation, $content, $toolCalls, $model, $usage, $latencyMs, $finishReason, $blockedBy, $facts, $principal, $subject): AgentConversationMessage {
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
                'subject' => $subject,
                'fact_keys' => $facts?->all(),
                'principal_verification' => $principal?->verification->value,
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
                'content' => $result->display,
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
        TraceCursor $cursor,
        int $seq,
    ): AgentToolInvocation {
        if ($result->replayed && $result->idempotencyKey !== null) {
            return $this->existingOkInvocation($conversation, $result->idempotencyKey);
        }

        $required = $this->tools->has($call['name'])
            ? $this->tools->get($call['name'])->requiredVerification()
            : null;

        $factKeys = $result->facts->all();

        try {
            return DB::transaction(function () use ($conversation, $assistantMessage, $principal, $call, $result, $durationMs, $required, $factKeys, $cursor, $seq): AgentToolInvocation {
                return AgentToolInvocation::query()->create([
                    'agent_conversation_id' => $conversation->id,
                    'agent_conversation_message_id' => $assistantMessage->id,
                    'tool_call_id' => $call['id'],
                    'tool_key' => $call['name'],
                    'arguments' => ArgumentBag::normalise($call['arguments'] ?? []),
                    'result' => $result->toTraceResult(),
                    'result_summary' => $result->display,
                    'status' => $result->status,
                    'denied_reason' => $result->deniedReason,
                    'required_verification' => $required,
                    'principal_verification' => $principal->verification,
                    'duration_ms' => $durationMs,
                    'idempotency_key' => $result->idempotencyKey,
                    'result_type' => $result->resultType,
                    'result_id' => $result->resultId,
                    'fact_keys' => $factKeys !== [] ? $factKeys : null,
                    'turn' => $cursor->turn,
                    'seq' => $seq,
                    'model' => $cursor->model,
                    'prompt_version' => $cursor->promptVersion,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($result->idempotencyKey === null) {
                throw $e;
            }

            return $this->existingOkInvocation($conversation, $result->idempotencyKey);
        }
    }

    private function existingOkInvocation(AgentConversation $conversation, string $idempotencyKey): AgentToolInvocation
    {
        return AgentToolInvocation::query()
            ->where('agent_conversation_id', $conversation->id)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', ToolInvocationStatus::Ok)
            ->firstOrFail();
    }

    /**
     * @param  list<AgentToolInvocation>  $invocations
     * @param  array<string, mixed>|null  $detail
     * @param  list<AiUsageEvent>  $usageEvents
     * @param  list<array<string, mixed>>  $guardrailEvents
     */
    private function finishWithHandoff(
        AgentContext $ctx,
        FactBag $facts,
        array $invocations,
        HandoffReason $reason,
        HandoffTriggerSource $source,
        string $draft,
        ?array $detail = null,
        array $usageEvents = [],
        ?string $blockedBy = null,
        bool $persistAssistant = true,
        array $guardrailEvents = [],
        ?TraceCursor $cursor = null,
        ?int $assistantMessageId = null,
    ): AgentTurn {
        $draft = DisclosureGuard::appendIfNeeded($draft, $ctx);

        if ($persistAssistant) {
            $assistant = $this->persistAssistantMessage(
                $ctx->conversation,
                $draft,
                [],
                $ctx->agent->model,
                new Usage,
                null,
                'handoff',
                $blockedBy,
                $facts,
                $ctx->principal,
            );
            $assistantMessageId = $assistant->id;
            if ($cursor !== null) {
                $this->bindOpenTraceRows($cursor, $assistant->id, $usageEvents);
            }
        }

        $handoff = $this->writeHandoff($ctx->conversation, $reason, $source, $detail, $cursor, $assistantMessageId);
        $state = $reason === HandoffReason::BudgetExceeded
            ? ConversationState::Closed
            : ConversationState::AwaitingHuman;

        $this->touchConversation($ctx->conversation, $state);

        return new AgentTurn(
            $draft,
            $ctx->channel,
            $facts,
            $invocations,
            $handoff,
            $usageEvents,
            $state,
            $blockedBy,
            guardrailEvents: $guardrailEvents,
        );
    }

    private function shortCircuitHandoff(
        AgentContext $ctx,
        AgentPrincipal $principal,
        string $input,
        HandoffMatch $match,
        HandoffTriggerSource $source,
    ): AgentTurn {
        $userMessage = $this->persistUserMessage($ctx->conversation, $input);
        $cursor = TraceCursor::start($ctx, $userMessage->id);
        $this->persistInboundGuardrail($cursor, $match);
        $site = $this->siteFor($principal);

        return $this->finishWithHandoff(
            $ctx,
            FactBag::fromCustomerMessage($input, $site),
            [],
            $match->reason,
            $source,
            $match->cannedDraft,
            $match->detail,
            cursor: $cursor,
        );
    }

    /**
     * Reserve an ai_usage_events row, call the driver, then settle with real tokens.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<AgentTool>  $toolObjects
     * @param  list<AiUsageEvent>  $usageEvents
     */
    private function streamMetered(
        AiAgent $agent,
        AgentConversation $conversation,
        array $messages,
        array $toolObjects,
        string $model,
        ?Closure $onEvent,
        array &$usageEvents,
        TraceCursor $cursor,
    ): ModelResponse {
        $callId = (string) Str::uuid7();
        $provider = $this->providers->applyActiveCredentials() ?? (string) config('ai.default');
        AiUsageEvent::reserve(
            $callId,
            null,
            null,
            'agent',
            RequestId::get(),
            $agent->id,
            $conversation->id,
        );

        try {
            $response = $this->driver->stream(
                $messages,
                $toolObjects,
                $model,
                $onEvent === null ? null : fn (string $delta) => $onEvent('token', ['delta' => $delta]),
            );
        } catch (ModelTimeoutException $e) {
            $failed = AiUsageEvent::settle($callId, status: AiUsageEvent::STATUS_FAILED, provider: $provider, model: $model);
            if ($failed !== null) {
                $this->stampUsageEnvelope($failed, $cursor);
                $usageEvents[] = $failed;
            }
            SystemEvent::record('ai.turn.failed', $conversation, [
                'error' => 'timeout',
                'detail' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $failed = AiUsageEvent::settle($callId, status: AiUsageEvent::STATUS_FAILED, provider: $provider, model: $model);
            if ($failed !== null) {
                $this->stampUsageEnvelope($failed, $cursor);
                $usageEvents[] = $failed;
            }
            SystemEvent::record('ai.turn.failed', $conversation, [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $settled = AiUsageEvent::settle($callId, $response->usage, AiUsageEvent::STATUS_OK, $provider, $model);
        if ($settled !== null) {
            $this->stampUsageEnvelope($settled, $cursor);
            $usageEvents[] = $settled;
        }

        return $response;
    }

    private function stampUsageEnvelope(AiUsageEvent $event, TraceCursor $cursor): void
    {
        $event->fill([
            'turn' => $cursor->turn,
            'seq' => $cursor->allocateSeq(),
            'prompt_version' => $cursor->promptVersion,
        ]);
        $event->save();
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    private function writeHandoff(
        AgentConversation $conversation,
        HandoffReason $reason,
        HandoffTriggerSource $source,
        ?array $detail,
        ?TraceCursor $cursor = null,
        ?int $messageId = null,
    ): AgentHandoff {
        return DB::transaction(function () use ($conversation, $reason, $source, $detail, $cursor, $messageId): AgentHandoff {
            $row = [
                'agent_conversation_id' => $conversation->id,
                'agent_conversation_message_id' => $messageId,
                'reason' => $reason,
                'trigger_source' => $source,
                'detail' => $detail,
            ];
            if ($cursor !== null) {
                $row['turn'] = $cursor->turn;
                $row['seq'] = $cursor->allocateSeq();
                $row['model'] = $cursor->model;
                $row['prompt_version'] = $cursor->promptVersion;
            }

            return AgentHandoff::query()->create($row);
        });
    }

    private function touchConversation(AgentConversation $conversation, ConversationState $state): void
    {
        $conversation->state = $state;
        $conversation->last_turn_at = now();
        if ($state === ConversationState::Closed && $conversation->closed_at === null) {
            $conversation->closed_at = now();
        }
        $conversation->save();
    }

    private function nextSequence(AgentConversation $conversation): int
    {
        return (int) $conversation->messages()->max('sequence') + 1;
    }

    private function siteFor(AgentPrincipal $principal): ?Site
    {
        if ($principal->siteId === null) {
            return null;
        }

        return Site::query()->find($principal->siteId);
    }
}
