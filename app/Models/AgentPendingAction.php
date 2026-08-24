<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\CanonicalJson;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Auth\Concerns\VisibleToEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Operator-queued agent write proposal. Intent, never a result snapshot.
 *
 * @property int $id
 * @property int $agent_conversation_id
 * @property int $agent_tool_invocation_id
 * @property int $ai_agent_id
 * @property int $site_id
 * @property string $tool_key
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $preview
 * @property PendingActionStatus $status
 * @property int|null $resolved_by_employee_id
 * @property Carbon|null $resolved_at
 * @property string|null $rejection_reason
 * @property string|null $result_type
 * @property int|null $result_id
 * @property string|null $failure_reason
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AgentConversation $conversation
 * @property-read AgentToolInvocation $invocation
 * @property-read AiAgent $agent
 * @property-read Site $site
 * @property-read Employee|null $resolvedBy
 * @property-read Model|null $result
 */
class AgentPendingAction extends Model
{
    use HasFactory;
    use VisibleToEmployee;

    protected $fillable = [
        'agent_conversation_id',
        'agent_tool_invocation_id',
        'ai_agent_id',
        'site_id',
        'tool_key',
        'payload',
        'preview',
        'status',
        'resolved_by_employee_id',
        'resolved_at',
        'rejection_reason',
        'result_type',
        'result_id',
        'failure_reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'preview' => 'array',
            'status' => PendingActionStatus::class,
            'resolved_at' => 'datetime',
            'expires_at' => 'datetime',
            'result_id' => 'integer',
        ];
    }

    /**
     * Canonical JSON of stable write inputs. Approval compares this, never preview.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function canonicalPayload(array $payload): string
    {
        return CanonicalJson::encode($payload);
    }

    /** @param  Builder<static>  $query */
    public function scopeOperatorQueue(Builder $query): void
    {
        $query->whereHas('conversation', function (Builder $conversation): void {
            $conversation->where('origin', '<>', AgentOrigin::Demo);
        });
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    /** @return BelongsTo<AgentToolInvocation, $this> */
    public function invocation(): BelongsTo
    {
        return $this->belongsTo(AgentToolInvocation::class, 'agent_tool_invocation_id');
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by_employee_id');
    }

    /** @return MorphTo<Model, $this> */
    public function result(): MorphTo
    {
        return $this->morphTo();
    }
}
