<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One outbound or inbound guard verdict in an agent reasoning trace. Append-only.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property int|null $agent_conversation_message_id
 * @property int $turn
 * @property int $seq
 * @property string $guard
 * @property string $verdict
 * @property array<string, mixed>|null $detail
 * @property string|null $model
 * @property string $prompt_version
 * @property Carbon $created_at
 * @property-read AgentConversation $conversation
 * @property-read AgentConversationMessage|null $message
 */
class AgentGuardrailEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_conversation_id',
        'agent_conversation_message_id',
        'turn',
        'seq',
        'guard',
        'verdict',
        'detail',
        'model',
        'prompt_version',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
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
