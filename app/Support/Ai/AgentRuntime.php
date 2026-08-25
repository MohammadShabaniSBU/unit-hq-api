<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
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
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Guards\CannedReply;
use App\Support\Ai\Guards\DisclosureGuard;
use App\Support\Ai\Guards\GuardrailPipeline;
use App\Support\Ai\Guards\GuardrailVerdict;
use App\Support\Ai\Guards\HandoffMatch;
use App\Support\Ai\Guards\InboundGuardPipeline;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolDispatcher;
use App\Support\Ai\Tools\ToolRegistry;
use App\Support\Ai\Tools\ToolResult;
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
        $this->persistUserMessage($conversation, $input);

        $site = $this->siteFor($principal);
        $facts = FactBag::fromCustomerMessage($input, $site);
        /** @var list<\App\Support\Ai\Enums\ForbiddenClaimKey> $licensedClaims */
        $licensedClaims = [];
        $invocations = [];
        $usageEvents = [];
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
                $response = $this->streamMetered(
                    $agent,
                    $conversation,
                    $messages,
                    $toolObjects,
                    $model,
                    $onEvent,
                    $usageEvents,
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
                        $onEvent('tool.started', [
                            'tool_key' => $call['name'],
                            'arguments' => $call['arguments'],
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

                    $invocation = $this->persistInvocation(
                        $conversation,
                        $assistantMessage,
                        $principal,
                        $call,
                        $result,
                        $durationMs,
                    );
                    $invocations[] = $invocation;

                    if ($result->deniedReason === ToolDeniedReason::RequiresApproval) {
                        try {
                            $invocation->loadMissing('conversation');
                            app(PendingActionRecorder::class)->record($invocation, $result);
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

                    if ($result->status === ToolInvocationStatus::Ok) {
                        $facts->merge($result->facts);
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
                        'content' => $this->wrapUntrusted($result->display !== '' ? $result->display : ($result->message ?? '')),
                        'tool_call_id' => $call['id'],
                        'tool_name' => $call['name'],
                        'arguments' => $call['arguments'],
                    ];

                    if ($onEvent !== null) {
                        $onEvent('tool.finished', [
                            'tool_key' => $call['name'],
                            'status' => $result->status->value,
                            'denied_reason' => $result->deniedReason?->value,
                            'duration_ms' => $durationMs,
                            'result_summary' => $result->display !== '' ? $result->display : $result->message,
                        ]);
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
        );
        $draft = $verdict['draft'];
        $usageTotal = $verdict['usage'];
        $lastUsage = $verdict['lastUsage'];
        $lastLatencyMs = $verdict['lastLatencyMs'];
        $finishReason = $verdict['finishReason'];
        $outbound = $verdict['verdict'];

        if (! $outbound->passed) {
            $this->persistAssistantMessage(
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
            );
        }

        if ($outbound->mutatedDraft !== null) {
            $draft = $outbound->mutatedDraft;
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
                facts: $facts,
                principal: $principal,
                subject: $outbound->subject,
            );
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
    ): array {
        $retriedThisTurn = false;
        $verdict = $this->guards->check($draft, $facts, $ctx);
        $this->emitGuardrailEvents($onEvent, $verdict);

        if ($verdict->retry !== null && ! $retriedThisTurn) {
            $retriedThisTurn = true;
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
                    $verdict->events,
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
                $verdict = $this->retryAsBlock($verdict);
            } else {
                $draft = $response->content;
                $verdict = $this->guards->check($draft, $facts, $ctx);
                $this->emitGuardrailEvents($onEvent, $verdict);
                if ($verdict->retry !== null) {
                    $verdict = $this->retryAsBlock($verdict);
                }
            }
        } elseif ($verdict->retry !== null) {
            $verdict = $this->retryAsBlock($verdict);
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

    private function retryAsBlock(GuardrailVerdict $verdict): GuardrailVerdict
    {
        return GuardrailVerdict::block(
            $verdict->blockedBy ?? 'channel',
            $verdict->handoffReason ?? HandoffReason::Error,
            $verdict->detail,
            $verdict->events,
        );
    }

    private function emitGuardrailEvents(?Closure $onEvent, GuardrailVerdict $verdict): void
    {
        if ($onEvent === null) {
            return;
        }

        foreach ($verdict->events as $event) {
            $onEvent('guardrail', $event);
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
        if ($result->replayed && $result->idempotencyKey !== null) {
            return $this->existingOkInvocation($conversation, $result->idempotencyKey);
        }

        $required = $this->tools->has($call['name'])
            ? $this->tools->get($call['name'])->requiredVerification()
            : null;

        $factKeys = $result->facts->all();

        try {
            return DB::transaction(function () use ($conversation, $assistantMessage, $principal, $call, $result, $durationMs, $required, $factKeys): AgentToolInvocation {
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
                    'idempotency_key' => $result->idempotencyKey,
                    'result_type' => $result->resultType,
                    'result_id' => $result->resultId,
                    'fact_keys' => $factKeys !== [] ? $factKeys : null,
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
    ): AgentTurn {
        $draft = DisclosureGuard::appendIfNeeded($draft, $ctx);
        $handoff = $this->writeHandoff($ctx->conversation, $reason, $source, $detail);
        $state = $reason === HandoffReason::BudgetExceeded
            ? ConversationState::Closed
            : ConversationState::AwaitingHuman;

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
                $facts,
                $ctx->principal,
            );
        }

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
        $this->persistUserMessage($ctx->conversation, $input);
        $site = $this->siteFor($principal);

        return $this->finishWithHandoff(
            $ctx,
            FactBag::fromCustomerMessage($input, $site),
            [],
            $match->reason,
            $source,
            $match->cannedDraft,
            $match->detail,
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
    ): ModelResponse {
        $callId = (string) Str::uuid7();
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
            $failed = AiUsageEvent::settle($callId, status: AiUsageEvent::STATUS_FAILED, model: $model);
            if ($failed !== null) {
                $usageEvents[] = $failed;
            }
            SystemEvent::record('ai.turn.failed', $conversation, [
                'error' => 'timeout',
                'detail' => $e->getMessage(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $failed = AiUsageEvent::settle($callId, status: AiUsageEvent::STATUS_FAILED, model: $model);
            if ($failed !== null) {
                $usageEvents[] = $failed;
            }
            SystemEvent::record('ai.turn.failed', $conversation, [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $settled = AiUsageEvent::settle($callId, $response->usage, AiUsageEvent::STATUS_OK, null, $model);
        if ($settled !== null) {
            $usageEvents[] = $settled;
        }

        return $response;
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
