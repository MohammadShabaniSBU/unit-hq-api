<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use App\Models\Contact;
use App\Support\Ai\Eval\CassetteStore;
use App\Support\Ai\Eval\EvalUnexpectedModelCallException;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\Usage;

final class CassetteDriver implements ModelDriver
{
    /** @var list<ModelResponse> */
    private array $queue = [];

    public int $callCount = 0;

    public int $lastTransactionLevel = 0;

    /**
     * @param  list<ModelResponse>  $responses
     */
    public function load(array $responses): self
    {
        $this->queue = array_values($responses);
        $this->callCount = 0;

        return $this;
    }

    public function stream(array $messages, array $tools, string $model, ?Closure $onDelta): ModelResponse
    {
        $this->callCount++;
        $this->lastTransactionLevel = DB::transactionLevel();

        if ($this->queue === []) {
            throw new EvalUnexpectedModelCallException(
                'CassetteDriver: no recorded response for this model call (unexpected model call or cassette exhausted).',
            );
        }

        $response = array_shift($this->queue);
        $response = $this->applyLazyPlaceholders($response);

        if ($onDelta !== null && $response->content !== '') {
            $onDelta($response->content);
        }

        return $response;
    }

    private function applyLazyPlaceholders(ModelResponse $response): ModelResponse
    {
        $lazy = [
            '{{next_contact_id}}' => (string) ((int) Contact::query()->max('id')),
        ];

        $toolCalls = CassetteStore::interpolate($response->toolCalls, $lazy);
        if (! is_array($toolCalls)) {
            return $response;
        }

        /** @var list<array{name: string, id: string, arguments: array<string, mixed>}> $toolCalls */
        return new ModelResponse(
            $response->content,
            $toolCalls,
            $response->usage,
            $response->finishReason,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function responseFromArray(array $row): ModelResponse
    {
        $toolCalls = [];
        foreach ($row['tool_calls'] ?? [] as $i => $call) {
            $toolCalls[] = [
                'name' => (string) ($call['name'] ?? ''),
                'id' => (string) ($call['id'] ?? 'call_'.($i + 1)),
                'arguments' => is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
            ];
        }

        $usage = $row['usage'] ?? [];

        return new ModelResponse(
            (string) ($row['content'] ?? ''),
            $toolCalls,
            new Usage(
                promptTokens: (int) ($usage['prompt_tokens'] ?? 1),
                completionTokens: (int) ($usage['completion_tokens'] ?? 1),
            ),
            (string) ($row['finish_reason'] ?? ($toolCalls === [] ? 'stop' : 'tool_calls')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function responseToArray(ModelResponse $response): array
    {
        return [
            'content' => $response->content,
            'tool_calls' => $response->toolCalls,
            'finish_reason' => $response->finishReason,
        ];
    }
}
