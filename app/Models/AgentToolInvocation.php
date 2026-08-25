<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\ArgumentBagCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One tool call in an agent turn. Append-only.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property int|null $agent_conversation_message_id
 * @property string|null $tool_call_id
 * @property string $tool_key
 * @property array<string, mixed> $arguments
 * @property array<string, mixed>|null $result
 * @property string|null $result_summary
 * @property ToolInvocationStatus $status
 * @property ToolDeniedReason|null $denied_reason
 * @property VerificationLevel|null $required_verification
 * @property VerificationLevel|null $principal_verification
 * @property int|null $duration_ms
 * @property string|null $idempotency_key
 * @property string|null $result_type
 * @property int|null $result_id
 * @property array<int, string|int>|null $fact_keys
 * @property int|null $turn
 * @property int|null $seq
 * @property string|null $model
 * @property string|null $prompt_version
 * @property Carbon $created_at
 * @property-read AgentConversation $conversation
 * @property-read AgentConversationMessage|null $message
 * @property-read AgentPendingAction|null $pendingAction
 */
class AgentToolInvocation extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_conversation_id',
        'agent_conversation_message_id',
        'tool_call_id',
        'tool_key',
        'arguments',
        'result',
        'result_summary',
        'status',
        'denied_reason',
        'required_verification',
        'principal_verification',
        'duration_ms',
        'idempotency_key',
        'result_type',
        'result_id',
        'fact_keys',
        'turn',
        'seq',
        'model',
        'prompt_version',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => ArgumentBagCast::class,
            'result' => 'array',
            'status' => ToolInvocationStatus::class,
            'denied_reason' => ToolDeniedReason::class,
            'required_verification' => VerificationLevel::class,
            'principal_verification' => VerificationLevel::class,
            'fact_keys' => 'array',
            'result_id' => 'integer',
        ];
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<AgentConversationMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AgentConversationMessage::class, 'agent_conversation_message_id');
    }

    /** @return HasOne<AgentPendingAction, $this> */
    public function pendingAction(): HasOne
    {
        return $this->hasOne(AgentPendingAction::class, 'agent_tool_invocation_id');
    }
}
