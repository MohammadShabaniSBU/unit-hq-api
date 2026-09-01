<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\ArgumentBag;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\Usage;
use RuntimeException;

final class FakeModelDriver implements ModelDriver
{
    /** @var list<ModelResponse> */
    private array $queue = [];

    public int $callCount = 0;

    public int $lastTransactionLevel = 0;

    public function enqueue(ModelResponse ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    public function enqueueText(string $content, ?Usage $usage = null): self
    {
        return $this->enqueue(new ModelResponse(
            $content,
            [],
            $usage ?? new Usage(promptTokens: 10, completionTokens: 5),
            'stop',
        ));
    }

    /**
     * @param  list<array{name: string, id?: string, arguments?: array<string, mixed>}>  $toolCalls
     */
    public function enqueueToolCalls(array $toolCalls, string $content = '', ?Usage $usage = null): self
    {
        $normalized = [];
        foreach ($toolCalls as $i => $call) {
            $normalized[] = [
                'name' => $call['name'],
                'id' => $call['id'] ?? 'call_'.($i + 1),
                'arguments' => ArgumentBag::normalise($call['arguments'] ?? []),
            ];
        }

        return $this->enqueue(new ModelResponse(
            $content,
            $normalized,
            $usage ?? new Usage(promptTokens: 10, completionTokens: 5),
            'tool_calls',
        ));
    }

    /** @var list<array<string, mixed>> */
    public array $lastMessages = [];

    /** @var list<string> */
    public array $lastToolKeys = [];

    public function stream(array $messages, array $tools, string $model, ?Closure $onDelta): ModelResponse
    {
        $this->callCount++;
        $this->lastTransactionLevel = DB::transactionLevel();
        $this->lastMessages = $messages;
        $this->lastToolKeys = [];
        foreach ($tools as $tool) {
            $this->lastToolKeys[] = $tool instanceof AgentTool ? $tool->key() : '';
        }

        if ($this->queue === []) {
            throw new RuntimeException('FakeModelDriver queue is empty.');
        }

        $response = array_shift($this->queue);

        if ($onDelta !== null && $response->content !== '') {
            $onDelta($response->content);
        }

        return $response;
    }
}
