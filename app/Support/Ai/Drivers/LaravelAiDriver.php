<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiProviderRegistry;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\ArgumentBag;
use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\TextDelta;
use LogicException;
use ReflectionObject;
use Throwable;

/**
 * Single-turn provider adapter. Uses StepTextGateway, never TextGenerationLoop.
 */
final class LaravelAiDriver implements ModelDriver
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly AiProviderRegistry $providers,
    ) {}

    public function stream(array $messages, array $tools, string $model, ?Closure $onDelta, ?int $timeoutSeconds = null): ModelResponse
    {
        $providerName = $this->providers->applyActiveCredentials();
        $provider = $this->manager->textProvider($providerName);
        $gateway = $this->stepGateway($provider);

        $names = ProviderToolName::fromTools($tools);
        [$instructions, $sdkMessages] = $this->toSdkMessages($messages, $names);
        $sdkTools = array_map(
            fn (AgentTool $tool): SchemaOnlySdkTool => new SchemaOnlySdkTool($tool, $names->toWire($tool->key())),
            $tools,
        );
        $timeoutSeconds = max(1, $timeoutSeconds ?? (int) ceil(((int) config('agents.turn_timeout_ms')) / 1000));
        $stepContext = new StepContext(stepNumber: 0, isFinalStep: true);

        try {
            if ($onDelta !== null) {
                $stream = $gateway->generateStreamStep(
                    (string) Str::uuid7(),
                    $provider,
                    $model,
                    $instructions,
                    $sdkMessages,
                    $sdkTools,
                    null,
                    null,
                    $timeoutSeconds,
                    $stepContext,
                );

                $step = $this->consumeStream($stream, $onDelta);
            } else {
                $step = $gateway->generateTextStep(
                    $provider,
                    $model,
                    $instructions,
                    $sdkMessages,
                    $sdkTools,
                    null,
                    null,
                    $timeoutSeconds,
                    $stepContext,
                );
            }
        } catch (Throwable $e) {
            if ($this->isTimeout($e)) {
                throw new ModelTimeoutException($e->getMessage());
            }

            throw $e;
        }

        if (! $step instanceof StepResponse) {
            throw new ModelTimeoutException('Provider returned no step response.');
        }

        return $this->toModelResponse($step, $names);
    }

    private function stepGateway(TextProvider $provider): StepTextGateway
    {
        if (method_exists($provider, 'textGateway')) {
            $gateway = $provider->textGateway();
            if ($gateway instanceof StepTextGateway) {
                return $gateway;
            }
        }

        $loop = $provider->textGenerationLoop();
        $reflection = new ReflectionObject($loop);
        foreach (['gateway', 'textGateway', 'stepGateway'] as $property) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $value = $prop->getValue($loop);
                if ($value instanceof StepTextGateway) {
                    return $value;
                }
            }
        }

        throw new LogicException('AI provider does not expose a StepTextGateway.');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array{0: ?string, 1: list<object>}
     */
    private function toSdkMessages(array $messages, ProviderToolName $names): array
    {
        $instructions = null;
        $sdk = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = (string) ($message['content'] ?? '');

            if ($role === 'system' && $instructions === null) {
                $instructions = $content;

                continue;
            }

            $sdk[] = match ($role) {
                'user' => new UserMessage($content),
                'assistant' => new AssistantMessage(
                    $content,
                    $this->toSdkToolCalls($message['tool_calls'] ?? [], $names),
                ),
                'tool' => new ToolResultMessage(collect([
                    new ToolResult(
                        (string) ($message['tool_call_id'] ?? ''),
                        $names->toWire((string) ($message['tool_name'] ?? '')),
                        ArgumentBag::normalise($message['arguments'] ?? []),
                        $content,
                    ),
                ])),
                default => new UserMessage($content),
            };
        }

        return [$instructions, $sdk];
    }

    /**
     * @param  list<array{name?: string, id?: string, arguments?: array<string, mixed>}>  $calls
     * @return Collection<int, ToolCall>
     */
    private function toSdkToolCalls(array $calls, ProviderToolName $names): Collection
    {
        return collect($calls)->map(fn (array $call): ToolCall => new ToolCall(
            (string) ($call['id'] ?? ''),
            $names->toWire((string) ($call['name'] ?? '')),
            ArgumentBag::normalise($call['arguments'] ?? []),
        ));
    }

    /**
     * @param  Generator<int, mixed, mixed, StepResponse|null>  $stream
     */
    private function consumeStream(Generator $stream, Closure $onDelta): ?StepResponse
    {
        foreach ($stream as $event) {
            if ($event instanceof TextDelta && $event->delta !== '') {
                $onDelta($event->delta);
            }
        }

        $return = $stream->getReturn();

        return $return instanceof StepResponse ? $return : null;
    }

    private function toModelResponse(StepResponse $step, ProviderToolName $names): ModelResponse
    {
        $toolCalls = [];
        foreach ($step->toolCalls as $call) {
            $toolCalls[] = [
                'name' => $names->fromWire($call->name),
                'id' => $call->id,
                'arguments' => ArgumentBag::normalise($call->arguments),
            ];
        }

        return new ModelResponse(
            $step->text,
            $toolCalls,
            $step->usage instanceof Usage ? $step->usage : new Usage,
            $step->finishReason->value,
        );
    }

    private function isTimeout(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || $e instanceof ConnectionException;
    }
}
