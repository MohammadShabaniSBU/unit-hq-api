<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConversationMessage extends TenantModel
{
    protected $table = 'agent_conversation_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'conversation_id',
        'role',
        'content',
        'agent',
        'attachments',
        'tool_calls',
        'tool_results',
        'usage',
        'meta',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }
}
