<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\VerificationLevel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Reasoning trace for one agent conversation (D-AI-3). Not a message store.
 *
 * @property int $id
 * @property int $ai_agent_id
 * @property AgentAudience $audience
 * @property AgentOrigin $origin
 * @property AgentChannel $channel
 * @property int|null $employee_id
 * @property int|null $created_by_employee_id
 * @property int|null $contact_id
 * @property int|null $site_id
 * @property VerificationLevel $verification_level
 * @property ConversationState $state
 * @property string|null $locale
 * @property int|null $message_thread_id
 * @property Carbon|null $last_turn_at
 * @property Carbon|null $agent_handback_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AiAgent $aiAgent
 * @property-read Employee|null $employee
 * @property-read Employee|null $createdByEmployee
 * @property-read Contact|null $contact
 * @property-read Site|null $site
 * @property-read MessageThread|null $messageThread
 * @property-read Collection<int, AgentConversationMessage> $messages
 * @property-read Collection<int, AgentToolInvocation> $toolInvocations
 * @property-read Collection<int, AgentHandoff> $handoffs
 * @property-read Collection<int, AgentPendingAction> $pendingActions
 * @property-read Collection<int, AgentGuardrailEvent> $guardrailEvents
 * @property-read Collection<int, AiUsageEvent> $usageEvents
 * @property-read Collection<int, AgentPrincipalPromotion> $principalPromotions
 */
class AgentConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_agent_id',
        'audience',
        'origin',
        'channel',
        'employee_id',
        'created_by_employee_id',
        'contact_id',
        'site_id',
        'verification_level',
        'state',
        'locale',
        'message_thread_id',
        'last_turn_at',
        'agent_handback_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'audience' => AgentAudience::class,
            'origin' => AgentOrigin::class,
            'channel' => AgentChannel::class,
            'verification_level' => VerificationLevel::class,
            'state' => ConversationState::class,
            'last_turn_at' => 'datetime',
            'agent_handback_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isCustomerFacing(): bool
    {
        return $this->audience === AgentAudience::Customer;
    }

    /**
     * Rebuild the principal from stored facts. Total match — unmappable rows throw.
     */
    public function principal(): AgentPrincipal
    {
        $locale = $this->locale ?? (string) config('app.locale');

        return match (true) {
            $this->audience === AgentAudience::Internal && $this->employee_id !== null => AgentPrincipal::employee($this->employee_id, $this->site_id, $locale),
            $this->audience === AgentAudience::Customer && $this->verification_level === VerificationLevel::Anonymous => AgentPrincipal::anonymous($this->site_id, $locale),
            $this->audience === AgentAudience::Customer
                && $this->verification_level === VerificationLevel::ChannelAsserted
                && $this->contact_id !== null => AgentPrincipal::channelAsserted($this->contact_id, $this->site_id, $locale),
            $this->audience === AgentAudience::Customer
                && $this->verification_level === VerificationLevel::Verified
                && $this->contact_id !== null => AgentPrincipal::verified($this->contact_id, $this->site_id, $locale),
            default => throw new LogicException('AgentConversation row cannot be mapped to an AgentPrincipal.'),
        };
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function createdByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<MessageThread, $this> */
    public function messageThread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class);
    }

    /** @return HasMany<AgentConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class)->orderBy('sequence');
    }

    /** @return HasMany<AgentToolInvocation, $this> */
    public function toolInvocations(): HasMany
    {
        return $this->hasMany(AgentToolInvocation::class);
    }

    /** @return HasMany<AgentHandoff, $this> */
    public function handoffs(): HasMany
    {
        return $this->hasMany(AgentHandoff::class);
    }

    /** @return HasMany<AgentPendingAction, $this> */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(AgentPendingAction::class);
    }

    /** @return HasMany<AgentGuardrailEvent, $this> */
    public function guardrailEvents(): HasMany
    {
        return $this->hasMany(AgentGuardrailEvent::class);
    }

    /** @return HasMany<AiUsageEvent, $this> */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(AiUsageEvent::class);
    }

    /** @return HasMany<AgentPrincipalPromotion, $this> */
    public function principalPromotions(): HasMany
    {
        return $this->hasMany(AgentPrincipalPromotion::class);
    }
}
