<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\HandoffTriggerSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Escalation from an agent conversation to a human. created_at only.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property HandoffReason $reason
 * @property HandoffTriggerSource $trigger_source
 * @property array<string, mixed>|null $detail
 * @property int|null $employee_id
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property-read AgentConversation $conversation
 * @property-read Employee|null $employee
 */
class AgentHandoff extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_conversation_id',
        'reason',
        'trigger_source',
        'detail',
        'employee_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => HandoffReason::class,
            'trigger_source' => HandoffTriggerSource::class,
            'detail' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
