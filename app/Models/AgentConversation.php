<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentConversation extends TenantModel
{
    protected $table = 'agent_conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'title'];

    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id')
            ->orderBy('created_at');
    }
}
