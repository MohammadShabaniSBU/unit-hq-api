<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tool call in an agent turn. Append-only.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property int|null $agent_conversation_message_id
 * @property string $tool_key
 * @property array<string, mixed> $arguments
 * @property array<string, mixed>|null $result
 * @property string|null $result_summary
 * @property ToolInvocationStatus $status
 * @property ToolDeniedReason|null $denied_reason
 * @property VerificationLevel|null $required_verification
 * @property VerificationLevel|null $principal_verification
 * @property int|null $duration_ms
 * @property Carbon $created_at
 * @property-read AgentConversation $conversation
 * @property-read AgentConversationMessage|null $message
 */
class AgentToolInvocation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_conversation_id',
        'agent_conversation_message_id',
        'tool_key',
        'arguments',
        'result',
        'result_summary',
        'status',
        'denied_reason',
        'required_verification',
        'principal_verification',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'status' => ToolInvocationStatus::class,
            'denied_reason' => ToolDeniedReason::class,
            'required_verification' => VerificationLevel::class,
            'principal_verification' => VerificationLevel::class,
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
}
