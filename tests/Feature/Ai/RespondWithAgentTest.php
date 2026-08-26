<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\ContractStatus;
use App\Events\InboundMessageReceived;
use App\Listeners\RespondWithAgent;
use App\Models\AgentChannelBinding;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentHandoff;
use App\Models\AgentPendingAction;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Ai\Drivers\FakeModelDriver;
use App\Support\Ai\Drivers\ModelDriver;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentMessageRole;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\OutsideHoursPolicy;
use App\Support\Ai\Enums\PendingActionStatus;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use Database\Seeders\AiAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCataloguePrices;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class RespondWithAgentTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    private FakeModelDriver $driver;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 10:00:00');
        config(['agents.inbound_debounce_seconds' => 0]);

        $this->driver = new FakeModelDriver;
        $this->app->instance(ModelDriver::class, $this->driver);

        $this->seed(AiAgentSeeder::class);
        $this->employee = Employee::factory()->manager()->create();
        $this->fakeCommunicationProviders();
    }

    #[Test]
    public function draft_binding_queues_channel_send_and_approve_emits_one_outbound(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();

        $this->handle($world['inbound']);

        $this->assertSame(0, $this->outboundCount($world['thread']));
        $pending = AgentPendingAction::query()->where('tool_key', 'channel.send')->first();
        $this->assertNotNull($pending);
        $this->assertSame(PendingActionStatus::Pending, $pending->status);
        $this->assertGreaterThan(0, $world['thread']->fresh()->unread_count);

        Sanctum::actingAs($this->employee);
        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.agent_drafts', 1)
            ->assertJsonPath('data.agent_handoffs', 0);

        $this->postJson("/api/agent-pending-actions/{$pending->id}/approve")->assertOk();

        $outbound = Message::query()
            ->where('message_thread_id', $world['thread']->id)
            ->where('direction', MessageDirection::Outbound)
            ->get();
        $this->assertCount(1, $outbound);
        $this->assertSame(MessageSource::AiAgent, $outbound->first()?->source);

        $assistant = AgentConversationMessage::query()
            ->where('emitted_message_id', $outbound->first()?->id)
            ->first();
        $this->assertNotNull($assistant);
        $this->assertSame($outbound->first()?->id, $assistant->emitted_message_id);
    }

    #[Test]
    public function auto_binding_writes_outbound_during_the_turn(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Auto);
        $this->enqueueSafeReply();

        $this->handle($world['inbound']);

        $this->assertSame(0, AgentPendingAction::query()->count());
        $outbound = Message::query()
            ->where('message_thread_id', $world['thread']->id)
            ->where('direction', MessageDirection::Outbound)
            ->get();
        $this->assertCount(1, $outbound);
        $this->assertSame(MessageSource::AiAgent, $outbound->first()?->source);
    }

    #[Test]
    public function absent_binding_skips_binding_off_and_creates_no_conversation(): void
    {
        $world = $this->smsWorld();
        AgentChannelBinding::query()->where('channel', AgentChannel::Sms)->update(['archived_at' => now()]);

        $this->handle($world['inbound']);

        $this->assertSkipped($world['inbound'], 'binding_off');
        $this->assertSame(0, AgentConversation::query()->count());
    }

    #[Test]
    public function existing_tenants_binding_skips_a_prospect(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft, BindingAudience::ExistingTenants);

        $this->handle($world['inbound']);

        $this->assertSkipped($world['inbound'], 'audience');
        $this->assertSame(0, AgentConversation::query()->count());
    }

    #[Test]
    public function sales_agent_skips_an_active_tenant(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->giveInForceContract($world['contact'], $world['site']);

        $this->handle($world['inbound']);

        $this->assertSkipped($world['inbound'], 'agent_ineligible');
        $this->assertSame(0, AgentConversation::query()->count());
    }

    #[Test]
    public function manual_reply_marks_the_thread_human_owned_until_resume(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($world['inbound']);

        $this->travel(1)->second();

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/inbox/threads/'.$world['thread']->id.'/reply', [
            'body_text' => 'I will take this from here.',
        ])->assertCreated();

        $followUp = $this->addInbound($world, 'Second question');
        $this->handle($followUp);
        $this->assertSkipped($followUp, 'human_owned');

        $this->postJson('/api/inbox/threads/'.$world['thread']->id.'/agent/resume')
            ->assertOk()
            ->assertJsonPath('data.state', ConversationState::Active->value);

        $this->travel(1)->second();

        $afterResume = $this->addInbound($world, 'Third question');
        $this->enqueueSafeReply();
        $this->handle($afterResume);

        $this->assertNull($this->skipFor($afterResume));
        $this->assertSame(2, AgentPendingAction::query()->where('tool_key', 'channel.send')->count());
    }

    #[Test]
    public function closed_whatsapp_window_hands_off_channel_constraint_without_sending(): void
    {
        $world = $this->whatsappWorld(lastInboundAt: now()->subHours(25));
        $this->bindWhatsapp(BindingMode::Draft);
        $this->enqueueSafeReply();

        $this->handle($world['inbound']);

        $handoff = AgentHandoff::query()->first();
        $this->assertNotNull($handoff);
        $this->assertSame(HandoffReason::ChannelConstraint, $handoff->reason);
        $this->assertSame(0, AgentPendingAction::query()->count());
        $this->assertSame(0, $this->outboundCount($world['thread']));
        $this->assertSame(ConversationState::AwaitingHuman, AgentConversation::query()->first()?->state);

        Sanctum::actingAs($this->employee);
        $this->getJson('/api/inbox/badge')
            ->assertOk()
            ->assertJsonPath('data.agent_handoffs', 1);
    }

    #[Test]
    public function duplicate_delivery_is_a_noop_on_the_precheck(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($world['inbound']);

        $this->handle($world['inbound']);

        $this->assertSkipped($world['inbound'], 'duplicate');
        $this->assertSame(1, AgentConversation::query()->count());
        $this->assertSame(1, AgentPendingAction::query()->where('tool_key', 'channel.send')->count());
    }

    #[Test]
    public function losing_subject_message_unique_completes_without_a_failed_job(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);

        $dummy = AgentConversation::factory()->create();
        $inboundId = $world['inbound']->id;

        AgentConversation::created(function () use ($dummy, $inboundId): void {
            static $armed = true;
            if (! $armed) {
                return;
            }
            $armed = false;

            // Insert after the inbound pre-check (conversation create) and before
            // persistUserMessage's savepoint so the unique pin survives that rollback.
            AgentConversationMessage::withoutEvents(function () use ($dummy, $inboundId): void {
                AgentConversationMessage::query()->create([
                    'agent_conversation_id' => $dummy->id,
                    'sequence' => 1,
                    'role' => AgentMessageRole::User,
                    'content' => 'competitor',
                    'subject_message_id' => $inboundId,
                ]);
            });
        });

        $this->handle($world['inbound']);

        $this->assertSkipped($world['inbound'], 'duplicate');
        if (Schema::hasTable('failed_jobs')) {
            $this->assertSame(0, DB::table('failed_jobs')->count());
        }
        $this->assertSame(
            1,
            AgentConversationMessage::query()->where('subject_message_id', $world['inbound']->id)->count(),
        );
    }

    #[Test]
    public function outside_hours_inbox_skips_and_answer_still_turns(): void
    {
        $world = $this->smsWorld();
        Setting::setGeneral(Setting::general()->with(sendWindowStart: '09:00', sendWindowEnd: '17:00'));
        $this->travelTo('2026-08-26 18:00:00');

        $this->bindSms(BindingMode::Draft, outsideHours: OutsideHoursPolicy::Inbox);
        $this->handle($world['inbound']);
        $this->assertSkipped($world['inbound'], 'outside_hours');

        $this->bindSms(BindingMode::Draft, outsideHours: OutsideHoursPolicy::Answer);
        $this->enqueueSafeReply();
        $second = $this->addInbound($world, 'Still here after hours');
        $this->handle($second);

        $this->assertNull($this->skipFor($second));
        $this->assertSame(1, AgentPendingAction::query()->where('tool_key', 'channel.send')->count());
    }

    #[Test]
    public function two_inbounds_in_the_debounce_window_drive_one_turn_from_the_last(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();

        $first = $world['inbound'];
        $last = $this->addInbound($world, 'And another thing');

        $this->handle($first);
        $this->assertSkipped($first, 'debounced');

        $this->handle($last);
        $this->assertNull($this->skipFor($last));
        $this->assertSame(1, AgentPendingAction::query()->where('tool_key', 'channel.send')->count());
        $this->assertTrue(
            AgentConversationMessage::query()->where('subject_message_id', $last->id)->exists(),
        );
        $this->assertFalse(
            AgentConversationMessage::query()->where('subject_message_id', $first->id)->exists(),
        );
    }

    #[Test]
    public function reject_edited_then_reply_annotates_detail_and_marks_human_owned(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($world['inbound']);

        $pending = AgentPendingAction::query()->where('tool_key', 'channel.send')->firstOrFail();

        $this->travel(1)->second();

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/agent-pending-actions/{$pending->id}/reject", [
            'resolution' => 'edited',
        ])->assertOk();

        $reply = $this->postJson('/api/inbox/threads/'.$world['thread']->id.'/reply', [
            'body_text' => 'Operator-edited body.',
            'agent_pending_action_id' => $pending->id,
        ]);
        $reply->assertCreated();

        $pending->refresh();
        $this->assertSame('edited', $pending->detail['resolution'] ?? null);
        $this->assertSame(hash('sha256', 'Operator-edited body.'), $pending->detail['edited_body_hash'] ?? null);
        $this->assertSame($reply->json('data.message.id'), $pending->detail['sent_message_id'] ?? null);

        $followUp = $this->addInbound($world, 'Are you still there?');
        $this->handle($followUp);
        $this->assertSkipped($followUp, 'human_owned');
    }

    #[Test]
    public function sms_draft_expiry_uses_the_default_ttl(): void
    {
        $ttl = (int) config('agents.pending_action_ttl_minutes', 120);

        $sms = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($sms['inbound']);

        $smsPending = AgentPendingAction::query()->where('tool_key', 'channel.send')->firstOrFail();
        $this->assertNull($smsPending->preview['window_closes_at'] ?? null);
        $this->assertEqualsWithDelta(
            now()->addMinutes($ttl)->getTimestamp(),
            $smsPending->expires_at->getTimestamp(),
            2,
        );
    }

    #[Test]
    public function whatsapp_draft_expiry_clamps_to_the_window(): void
    {
        $ttl = (int) config('agents.pending_action_ttl_minutes', 120);

        $wa = $this->whatsappWorld(lastInboundAt: now()->subHours(23)->subMinutes(30));
        $this->bindWhatsapp(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($wa['inbound']);

        $waPending = AgentPendingAction::query()->where('tool_key', 'channel.send')->firstOrFail();
        $closes = $wa['thread']->fresh()->last_inbound_at?->copy()->addDay();
        $this->assertNotNull($closes);
        $this->assertNotNull($waPending->preview['window_closes_at'] ?? null);
        $this->assertEqualsWithDelta(
            $closes->getTimestamp(),
            $waPending->expires_at->getTimestamp(),
            2,
        );
        $this->assertTrue($waPending->expires_at->lt(now()->addMinutes($ttl)));
    }

    #[Test]
    public function reply_backfill_rejects_a_pending_action_from_another_thread(): void
    {
        $world = $this->smsWorld();
        $other = $this->smsWorld('+15550002222', $world['site'], $world['account']);
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($world['inbound']);

        $pending = AgentPendingAction::query()->where('tool_key', 'channel.send')->firstOrFail();

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/agent-pending-actions/{$pending->id}/reject", [
            'resolution' => 'edited',
        ])->assertOk();

        $response = $this->postJson('/api/inbox/threads/'.$other['thread']->id.'/reply', [
            'body_text' => 'Wrong thread body.',
            'agent_pending_action_id' => $pending->id,
        ]);
        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.agent_pending_action_id'));

        $pending->refresh();
        $this->assertArrayNotHasKey('sent_message_id', $pending->detail ?? []);
        $this->assertSame(1, $this->outboundCount($other['thread']));
    }

    #[Test]
    public function reply_backfill_rejects_a_pending_action_that_is_not_rejected_as_edited(): void
    {
        $world = $this->smsWorld();
        $this->bindSms(BindingMode::Draft);
        $this->enqueueSafeReply();
        $this->handle($world['inbound']);

        $pending = AgentPendingAction::query()->where('tool_key', 'channel.send')->firstOrFail();

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/inbox/threads/'.$world['thread']->id.'/reply', [
            'body_text' => 'Still pending.',
            'agent_pending_action_id' => $pending->id,
        ])->assertStatus(422);

        $pending->refresh();
        $this->assertNull($pending->detail);
        $this->assertSame(PendingActionStatus::Pending, $pending->status);
        $this->assertSame(1, $this->outboundCount($world['thread']));
    }

    /**
     * @return array{site: Site, account: CommunicationAccount, contact: Contact, thread: MessageThread, inbound: Message}
     */
    private function smsWorld(
        string $customerPhone = '+15551230001',
        ?Site $site = null,
        ?CommunicationAccount $account = null,
    ): array {
        $site ??= Site::factory()->create(['timezone' => 'Europe/Madrid']);
        $account ??= $this->seedSmsAccount($site);
        $contact = Contact::factory()->create();
        $this->givePrimaryPhone($contact, $customerPhone);

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Sms,
            'channel_key' => $customerPhone,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => 1,
        ]);

        $inbound = $this->writeInbound(
            $thread,
            $account,
            $customerPhone,
            '+15550001111',
            'Do you have a unit near the centre?',
        );

        return compact('site', 'account', 'contact', 'thread', 'inbound');
    }

    /**
     * @return array{site: Site, account: CommunicationAccount, contact: Contact, thread: MessageThread, inbound: Message}
     */
    private function whatsappWorld(\DateTimeInterface $lastInboundAt): array
    {
        $site = Site::query()->orderBy('id')->first()
            ?? Site::factory()->create(['timezone' => 'Europe/Madrid']);
        $account = $this->seedWhatsappAccount($site);
        $contact = Contact::factory()->create();
        $this->givePrimaryWhatsapp($contact, '+15551239999');

        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551239999',
            'last_message_at' => $lastInboundAt,
            'last_inbound_at' => $lastInboundAt,
            'unread_count' => 1,
        ]);

        $inbound = $this->writeInbound(
            $thread,
            $account,
            '+15551239999',
            '+15550009999',
            'Can you send me the hours?',
        );

        return compact('site', 'account', 'contact', 'thread', 'inbound');
    }

    private function bindSms(
        BindingMode $mode,
        BindingAudience $audience = BindingAudience::KnownContacts,
        OutsideHoursPolicy $outsideHours = OutsideHoursPolicy::Inbox,
    ): void {
        AgentChannelBinding::query()
            ->where('channel', AgentChannel::Sms)
            ->whereNull('site_id')
            ->update([
                'mode' => $mode,
                'audience' => $audience,
                'outside_hours' => $outsideHours,
                'archived_at' => null,
            ]);
    }

    private function bindWhatsapp(BindingMode $mode): void
    {
        AgentChannelBinding::query()
            ->where('channel', AgentChannel::Whatsapp)
            ->whereNull('site_id')
            ->update([
                'mode' => $mode,
                'audience' => BindingAudience::KnownContacts,
                'outside_hours' => OutsideHoursPolicy::Answer,
                'archived_at' => null,
            ]);
    }

    private function enqueueSafeReply(): void
    {
        $this->driver->enqueueText('Thanks for writing in. Which neighbourhood works for you?');
    }

    private function handle(Message $message): void
    {
        $thread = $message->thread;
        $this->assertNotNull($thread);

        app(RespondWithAgent::class)->handle(new InboundMessageReceived(
            $message->id,
            $thread->id,
            (int) $thread->contact_id,
            $thread->channel instanceof Channel ? $thread->channel : Channel::from((string) $thread->channel),
            false,
        ));
    }

    private function addInbound(array $world, string $body): Message
    {
        return $this->writeInbound(
            $world['thread'],
            $world['account'],
            (string) $world['thread']->channel_key,
            (string) Message::query()->where('message_thread_id', $world['thread']->id)->value('to_address'),
            $body,
        );
    }

    private function writeInbound(
        MessageThread $thread,
        CommunicationAccount $account,
        string $from,
        string $to,
        string $body,
    ): Message {
        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'communication_account_id' => $account->id,
            'direction' => MessageDirection::Inbound,
            'status' => MessageStatus::Received,
            'body_text' => $body,
            'from_address' => $from,
            'to_address' => $to,
            'source' => MessageSource::System,
            'auto_generated' => false,
            'sent_at' => now(),
        ]);

        $thread->forceFill([
            'last_message_at' => now(),
            'unread_count' => max(1, (int) $thread->unread_count),
        ])->save();

        return $message;
    }

    private function outboundCount(MessageThread $thread): int
    {
        return Message::query()
            ->where('message_thread_id', $thread->id)
            ->where('direction', MessageDirection::Outbound)
            ->count();
    }

    private function skipFor(Message $message): ?string
    {
        $event = SystemEvent::query()
            ->where('event', 'ai.inbound.skipped')
            ->where('subject_id', $message->id)
            ->orderByDesc('id')
            ->first();

        $reason = $event?->payload['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    private function assertSkipped(Message $message, string $reason): void
    {
        $this->assertSame($reason, $this->skipFor($message));
    }

    private function giveInForceContract(Contact $contact, Site $site): void
    {
        $class = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $class->id,
            $site->id,
            $this->employee->id,
            ['amount' => '150.00', 'currency' => 'EUR'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
        ]);
        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => $contract->start_date,
            'effective_to' => null,
        ]);
    }
}
