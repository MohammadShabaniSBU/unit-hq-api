<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentPendingAction;
use App\Models\AgentToolInvocation;
use App\Models\AiAgent;
use App\Models\MessageThread;
use App\Models\Site;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Communications\Channel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One deterministic inbox thread with a pending channel.send draft so S26-08
 * can develop the Inbox card before a live SMS arrives. No random draws.
 * Binding mode (not agent_write_policies) is the switch — this seeder does
 * not insert a write-policy row for channel.send.
 */
class AgentInboxDraftSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (AgentPendingAction::query()
            ->where('tool_key', 'channel.send')
            ->where('status', PendingActionStatus::Pending)
            ->exists()
        ) {
            return;
        }

        $sales = AiAgent::query()->where('key', 'sales')->first();
        $thread = MessageThread::query()
            ->where('channel', Channel::Sms)
            ->whereNotNull('contact_id')
            ->orderBy('id')
            ->first();
        $site = Site::query()->orderBy('id')->first();

        if ($sales === null || $thread === null || $site === null) {
            return;
        }

        $conversation = AgentConversation::query()->firstOrCreate(
            [
                'message_thread_id' => $thread->id,
                'origin' => AgentOrigin::Inbox,
            ],
            [
                'ai_agent_id' => $sales->id,
                'audience' => AgentAudience::Customer,
                'channel' => AgentChannel::Sms,
                'contact_id' => $thread->contact_id,
                'site_id' => $site->id,
                'verification_level' => VerificationLevel::ChannelAsserted,
                'state' => ConversationState::Active,
                'locale' => (string) config('app.locale'),
            ],
        );

        $assistant = $conversation->messages()
            ->where('role', AgentMessageRole::Assistant)
            ->orderByDesc('sequence')
            ->first();

        if ($assistant === null) {
            $next = (int) $conversation->messages()->max('sequence');
            AgentConversationMessage::query()->create([
                'agent_conversation_id' => $conversation->id,
                'sequence' => $next + 1,
                'role' => AgentMessageRole::User,
                'content' => 'Hi, I am looking for a unit.',
            ]);
            $assistant = AgentConversationMessage::query()->create([
                'agent_conversation_id' => $conversation->id,
                'sequence' => $next + 2,
                'role' => AgentMessageRole::Assistant,
                'content' => 'Thanks for writing in. Which neighbourhood works for you?',
            ]);
        }

        $body = (string) ($assistant->content ?? 'Thanks for writing in. Which neighbourhood works for you?');
        $payload = [
            'site_id' => $site->id,
            'message_thread_id' => $thread->id,
            'body' => $body,
            'agent_conversation_message_id' => $assistant->id,
        ];
        $preview = [
            'from_identity' => '+15550001111',
            'segments' => 1,
            'encoding' => 'gsm7',
            'window_closes_at' => null,
        ];

        $invocation = AgentToolInvocation::query()->create([
            'agent_conversation_id' => $conversation->id,
            'agent_conversation_message_id' => $assistant->id,
            'tool_key' => 'channel.send',
            'arguments' => [
                'message_thread_id' => $thread->id,
                'body' => $body,
                'agent_conversation_message_id' => $assistant->id,
            ],
            'result' => [
                'payload' => $payload,
                'preview' => $preview,
            ],
            'status' => ToolInvocationStatus::Denied,
            'denied_reason' => ToolDeniedReason::RequiresApproval,
        ]);

        AgentPendingAction::query()->create([
            'agent_conversation_id' => $conversation->id,
            'agent_tool_invocation_id' => $invocation->id,
            'ai_agent_id' => $sales->id,
            'site_id' => $site->id,
            'tool_key' => 'channel.send',
            'payload' => $payload,
            'preview' => $preview,
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes((int) config('agents.pending_action_ttl_minutes', 120)),
        ]);
    }
}
