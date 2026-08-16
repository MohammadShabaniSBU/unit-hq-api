<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Operational AI usage telemetry (reserve → settle). Not a ledger.
 *
 * Status is mutable by design — invariant 3 does not apply.
 *
 * @property int $id
 * @property string $call_id
 * @property int|null $employee_id
 * @property int|null $ai_agent_id
 * @property int|null $agent_conversation_id
 * @property string|null $conversation_id
 * @property string $purpose
 * @property string|null $provider
 * @property string|null $model
 * @property string $status
 * @property int $input_tokens
 * @property int $cached_input_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property bool $tokens_estimated
 * @property int $tool_calls
 * @property int|null $duration_ms
 * @property string|null $request_id
 * @property array|null $raw_usage
 * @property Carbon $started_at
 * @property Carbon|null $settled_at
 */
class AiUsageEvent extends Model
{
    public const STATUS_STARTED = 'started';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FAILED_OVER = 'failed_over';

    public const STATUS_ORPHANED = 'orphaned';

    protected $fillable = [
        'call_id',
        'employee_id',
        'ai_agent_id',
        'agent_conversation_id',
        'conversation_id',
        'purpose',
        'provider',
        'model',
        'status',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'tokens_estimated',
        'tool_calls',
        'duration_ms',
        'request_id',
        'raw_usage',
        'started_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'tokens_estimated' => 'boolean',
            'raw_usage' => 'array',
            'started_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class);
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function agentConversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class);
    }

    public static function reserve(
        ?string $callId,
        ?int $employeeId,
        ?string $conversationId = null,
        string $purpose = 'copilot',
        ?string $requestId = null,
    ): ?self {
        if ($callId === null || $callId === '') {
            return null;
        }

        return static::query()->firstOrCreate(
            ['call_id' => $callId],
            [
                'employee_id' => $employeeId,
                'conversation_id' => $conversationId,
                'purpose' => $purpose,
                'status' => self::STATUS_STARTED,
                'started_at' => now(),
                'request_id' => $requestId,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|Usage|null  $raw
     */
    public static function settle(
        ?string $callId,
        ?Usage $usage = null,
        string $status = self::STATUS_OK,
        ?string $provider = null,
        ?string $model = null,
        array|Usage|null $raw = null,
    ): ?self {
        if ($callId === null || $callId === '') {
            return null;
        }

        $event = static::query()->where('call_id', $callId)->first();
        if ($event === null) {
            return null;
        }

        if ($event->settled_at !== null) {
            return $event;
        }

        $usage ??= new Usage;
        $input = $usage->promptTokens;
        $cached = $usage->cacheReadInputTokens;
        $output = $usage->completionTokens;
        $reasoning = $usage->reasoningTokens;
        $estimated = ($input + $cached + $output + $reasoning) === 0
            && $status === self::STATUS_OK;

        $rawPayload = match (true) {
            $raw instanceof Usage => $raw->toArray(),
            is_array($raw) => $raw,
            default => $usage->toArray(),
        };

        $event->fill([
            'status' => $status,
            'input_tokens' => $input,
            'cached_input_tokens' => $cached,
            'output_tokens' => $output,
            'reasoning_tokens' => $reasoning,
            'tokens_estimated' => $estimated,
            'provider' => $provider ?? $event->provider,
            'model' => $model ?? $event->model,
            'raw_usage' => $rawPayload,
            'duration_ms' => (int) $event->started_at->diffInMilliseconds(now(), absolute: true),
            'settled_at' => now(),
        ]);
        $event->save();

        return $event;
    }

    public static function markFailed(?string $callId): ?self
    {
        return static::settle($callId, status: self::STATUS_FAILED, raw: []);
    }

    public static function markFailedOver(
        ?string $callId,
        ?string $provider = null,
        ?string $model = null,
    ): ?self {
        return static::settle(
            $callId,
            status: self::STATUS_FAILED_OVER,
            provider: $provider,
            model: $model,
            raw: [],
        );
    }

    public static function incrementToolCalls(?string $callId): void
    {
        if ($callId === null || $callId === '') {
            return;
        }

        static::query()
            ->where('call_id', $callId)
            ->whereNull('settled_at')
            ->increment('tool_calls');
    }

    public static function markOrphansOlderThan(Carbon $cutoff): int
    {
        return static::query()
            ->where('status', self::STATUS_STARTED)
            ->whereNull('settled_at')
            ->where('started_at', '<', $cutoff)
            ->update([
                'status' => self::STATUS_ORPHANED,
                'settled_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function totalTokens(): int
    {
        return $this->input_tokens
            + $this->cached_input_tokens
            + $this->output_tokens
            + $this->reasoning_tokens;
    }
}
