<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\ContactChannelType;
use App\Enums\CredentialStatus;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\WhatsappTemplate;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaComposerTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private CommunicationAccount $account;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $this->site = Site::factory()->create();
        $this->account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Whatsapp,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'project_id' => 'proj-test',
                'key_id' => 'key-id',
                'key_secret' => 'key-secret',
                'app_id' => 'app-test',
                'region' => 'us',
            ],
            'status' => CredentialStatus::Connected,
        ]);
        SiteSenderIdentity::query()->create([
            'site_id' => $this->site->id,
            'channel' => Channel::Whatsapp,
            'from_number' => '+15550009999',
        ]);

        $seq = 0;
        Http::fake([
            'us.conversation.api.sinch.com/*' => function () use (&$seq) {
                $seq++;

                return Http::response([
                    'message_id' => '01WA-COMP-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                ], 200);
            },
        ]);
    }

    public function test_window_states_and_variable_fill(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Marcus', 'last_name' => 'Reed']);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Whatsapp,
            'value' => '+15551234567',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        WhatsappTemplate::query()->create([
            'name' => 'hello_util',
            'language' => 'en',
            'category' => 'utility',
            'body' => 'Hi {{1}}, thanks for writing.',
            'variables' => [[
                'index' => 1,
                'label' => 'First name',
                'token_default' => 'contact.first_name',
                'sample' => 'Sam',
            ]],
            'status' => WhatsappTemplate::STATUS_APPROVED,
            'communication_account_id' => $this->account->id,
        ]);

        $inboundAt = Carbon::parse('2026-08-01 12:00:00', 'UTC');
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Whatsapp,
            'channel_key' => '+15551234567',
            'last_message_at' => $inboundAt,
            'last_inbound_at' => $inboundAt,
            'unread_count' => 0,
        ]);

        // Open window: compose-context + free-form session reply.
        Carbon::setTestNow(Carbon::parse('2026-08-02 11:00:00', 'UTC'));
        $ctx = $this->getJson("/api/inbox/threads/{$thread->id}/compose-context");
        $ctx->assertOk();
        $this->assertTrue($ctx->json('data.whatsapp_window.open'));
        $this->assertNotNull($ctx->json('data.whatsapp_window.closes_at'));
        $this->assertTrue($ctx->json('data.whatsapp_consent.has_channel'));
        $this->assertSame('Marcus', $ctx->json('data.templates.0.resolved_variables.0'));

        $session = $this->postJson("/api/inbox/threads/{$thread->id}/reply", [
            'body_text' => 'Free-form inside window',
        ]);
        $session->assertCreated();
        $this->assertSame(
            'Free-form inside window',
            Message::query()->findOrFail($session->json('data.message.id'))->body_text,
        );

        // Closed window: session refused; template send with resolved fill.
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:01', 'UTC'));
        $closedCtx = $this->getJson("/api/inbox/threads/{$thread->id}/compose-context");
        $this->assertFalse($closedCtx->json('data.whatsapp_window.open'));

        $refused = $this->postJson("/api/inbox/threads/{$thread->id}/reply", [
            'body_text' => 'Should fail',
        ]);
        $refused->assertStatus(422);
        $this->assertSame('whatsapp.window_closed', $refused->json('errors.reason'));

        $filled = 'Marcus';
        $templateReply = $this->postJson("/api/inbox/threads/{$thread->id}/reply", [
            'whatsapp_template_name' => 'hello_util',
            'variables' => [$filled],
        ]);
        $templateReply->assertCreated();

        $message = Message::query()->findOrFail($templateReply->json('data.message.id'));
        $this->assertSame('Hi Marcus, thanks for writing.', $message->body_text);
        $this->assertSame(
            'Hi Marcus, thanks for writing.',
            $templateReply->json('data.preview_body'),
        );
        $this->assertSame(
            $message->body_text,
            $templateReply->json('data.preview_body'),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
