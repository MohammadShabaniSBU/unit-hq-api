<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\AgentMessageRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One turn in an agent reasoning trace. Append-only.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property int $sequence
 * @property AgentMessageRole $role
 * @property string|null $content
 * @property array<int, mixed>|null $tool_calls
 * @property string|null $tool_call_id
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $latency_ms
 * @property string|null $finish_reason
 * @property string|null $blocked_by
 * @property string|null $subject
 * @property array<int, string>|null $fact_keys
 * @property string|null $principal_verification
 * @property int|null $emitted_message_id
 * @property int|null $subject_message_id
 * @property Carbon $created_at
 * @property-read AgentConversation $conversation
 * @property-read Message|null $emittedMessage
 * @property-read Message|null $subjectMessage
 */
class AgentConversationMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_conversation_id',
        'sequence',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'model',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'finish_reason',
        'blocked_by',
        'subject',
        'fact_keys',
        'principal_verification',
        'emitted_message_id',
        'subject_message_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => AgentMessageRole::class,
            'tool_calls' => 'array',
            'fact_keys' => 'array',
        ];
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function emittedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'emitted_message_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function subjectMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'subject_message_id');
    }
}
