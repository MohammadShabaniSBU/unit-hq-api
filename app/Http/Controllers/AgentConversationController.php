<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LogChannel;
use App\Http\Resources\AgentConversationResource;
use App\Models\AgentConversation;
use App\Models\AiAgent;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\InboundSiteContext;
use App\Support\Ai\Sse\AgentTurnSse;
use App\Support\Auth\Permission;
use App\Support\Communications\SiteLocale;
use App\Support\RecordsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'origin' => ['sometimes', Rule::enum(AgentOrigin::class)],
            'ai_agent_id' => ['sometimes', 'integer', 'exists:ai_agents,id'],
            'state' => ['sometimes', Rule::enum(ConversationState::class)],
        ]);

        $query = AgentConversation::query()
            ->with('aiAgent')
            ->latest('id');

        if ($employee->siteIdsFor(Permission::AiAgentUse) !== null) {
            $query->where('created_by_employee_id', $employee->id);
        }

        if (isset($validated['origin'])) {
            $query->where('origin', $validated['origin']);
        }
        if (isset($validated['ai_agent_id'])) {
            $query->where('ai_agent_id', $validated['ai_agent_id']);
        }
        if (isset($validated['state'])) {
            $query->where('state', $validated['state']);
        }

        return $this->paginated(
            $query->paginate($this->perPage())->through(
                fn (AgentConversation $conversation) => AgentConversationResource::make($conversation),
            ),
            'Conversations retrieved successfully.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);

        /** @var Employee $employee */
        $employee = $request->user();

        $validated = $request->validate([
            'agent_key' => ['required', 'string'],
            'channel' => ['required', Rule::enum(AgentChannel::class)->except([AgentChannel::Voice])],
            'origin' => ['required', Rule::enum(AgentOrigin::class)],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'verification_level' => [
                'prohibited_unless:origin,demo',
                'required_if:origin,demo',
                Rule::enum(VerificationLevel::class),
            ],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'communication_account_id' => ['nullable', 'integer', 'exists:communication_accounts,id'],
            'inbound_destination' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', 'string', 'max:5'],
        ]);

        $origin = AgentOrigin::from($validated['origin']);
        if ($origin === AgentOrigin::Demo && ! filter_var(config('agents.demo_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages([
                'origin' => ['errors.agent.demo_disabled'],
            ]);
        }

        $agent = AiAgent::query()->active()->where('key', $validated['agent_key'])->first();
        if ($agent === null) {
            throw ValidationException::withMessages([
                'agent_key' => ['errors.agent.unknown_or_inactive'],
            ]);
        }

        $contactId = isset($validated['contact_id']) ? (int) $validated['contact_id'] : null;
        $verification = $this->resolveVerification($origin, $validated['verification_level'] ?? null, $contactId);

        if ($verification === VerificationLevel::Verified && $contactId === null) {
            throw ValidationException::withMessages([
                'verification_level' => ['errors.agent.verified_requires_contact'],
            ]);
        }

        $audience = $this->deriveAudience($origin, $contactId);
        $siteId = $this->resolveSiteId($validated);
        $locale = $this->resolveLocale($validated['locale'] ?? null, $contactId, $siteId);

        $conversation = AgentConversation::query()->create([
            'ai_agent_id' => $agent->id,
            'audience' => $audience,
            'origin' => $origin,
            'channel' => AgentChannel::from($validated['channel']),
            'employee_id' => $audience === AgentAudience::Internal ? $employee->id : null,
            'created_by_employee_id' => $employee->id,
            'contact_id' => $contactId,
            'site_id' => $siteId,
            'verification_level' => $verification,
            'state' => ConversationState::Active,
            'locale' => $locale,
        ]);

        RecordsActivity::log(
            LogChannel::Ai,
            'agent.conversation.started',
            $conversation,
            [
                'agent_key' => $agent->key,
                'channel' => $conversation->channel->value,
                'origin' => $conversation->origin->value,
                'audience' => $conversation->audience->value,
                'verification_level' => $conversation->verification_level->value,
            ],
        );

        $conversation->load('aiAgent');

        return $this->created(
            AgentConversationResource::make($conversation),
            'Conversation created successfully.',
        );
    }

    public function show(AgentConversation $agentConversation): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);
        $this->authorize('view', $agentConversation);

        $agentConversation->load([
            'aiAgent',
            'messages',
            'toolInvocations.pendingAction',
            'handoffs',
            'guardrailEvents',
            'usageEvents',
        ]);

        return $this->success(
            AgentConversationResource::make($agentConversation),
            'Conversation retrieved successfully.',
        );
    }

    public function storeTurn(Request $request, AgentConversation $agentConversation): StreamedResponse|JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);
        $this->authorize('view', $agentConversation);

        if ($agentConversation->state !== ConversationState::Active) {
            return $this->error('errors.agent.conversation_not_active', [], 409);
        }

        $validated = $request->validate([
            'input' => ['required', 'string', 'max:8000'],
        ]);

        return app(AgentTurnSse::class)->stream($agentConversation, $validated['input']);
    }

    public function close(AgentConversation $agentConversation): JsonResponse
    {
        Gate::authorize(Permission::AiAgentUse->value);
        $this->authorize('view', $agentConversation);

        if ($agentConversation->state !== ConversationState::Closed) {
            $agentConversation->state = ConversationState::Closed;
            $agentConversation->closed_at = now();
            $agentConversation->save();
        }

        $agentConversation->load([
            'aiAgent',
            'messages',
            'toolInvocations.pendingAction',
            'handoffs',
            'guardrailEvents',
            'usageEvents',
        ]);

        return $this->success(
            AgentConversationResource::make($agentConversation),
            'Conversation closed successfully.',
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSiteId(array $validated): ?int
    {
        if (isset($validated['site_id'])) {
            return (int) $validated['site_id'];
        }

        $account = isset($validated['communication_account_id'])
            ? CommunicationAccount::query()->find((int) $validated['communication_account_id'])
            : null;

        return InboundSiteContext::resolve(
            AgentChannel::from($validated['channel']),
            $account,
            isset($validated['inbound_destination']) ? (string) $validated['inbound_destination'] : null,
        );
    }

    private function deriveAudience(AgentOrigin $origin, ?int $contactId): AgentAudience
    {
        if ($contactId !== null || $origin === AgentOrigin::Demo) {
            return AgentAudience::Customer;
        }

        return AgentAudience::Internal;
    }

    private function resolveVerification(AgentOrigin $origin, mixed $submitted, ?int $contactId): VerificationLevel
    {
        if ($origin === AgentOrigin::Demo) {
            return $submitted instanceof VerificationLevel
                ? $submitted
                : VerificationLevel::from((string) $submitted);
        }

        return $contactId !== null
            ? VerificationLevel::ChannelAsserted
            : VerificationLevel::Anonymous;
    }

    private function resolveLocale(?string $requested, ?int $contactId, ?int $siteId): string
    {
        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        if ($contactId !== null) {
            $contact = Contact::query()->find($contactId);
            if (is_string($contact?->locale) && $contact->locale !== '') {
                return $contact->locale;
            }
        }

        $site = $siteId !== null ? Site::query()->find($siteId) : null;

        return SiteLocale::for($site);
    }
}
