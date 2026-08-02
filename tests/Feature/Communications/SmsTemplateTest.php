<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\ContactChannelType;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Contact;
use App\Models\ContactChannel;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Support\Communications\Channel;
use App\Support\Communications\Messages\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AutomationHarness;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class SmsTemplateTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    private Site $site;

    private TemplateFamily $family;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $seq = 0;
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-test-1'], 201),
            'api.twilio.com/*' => function () use (&$seq) {
                $seq++;

                return Http::response(['sid' => 'SM-sms-tpl-'.$seq], 201);
            },
            'us.sms.api.sinch.com/*' => Http::response(['id' => '01FC-sinch-test'], 201),
            'api.aircall.io/*' => Http::response(['ping' => 'pong'], 200),
        ]);
        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $this->site = Site::factory()->create();
        $this->seedSmsAccount($this->site);

        $this->family = TemplateFamily::query()->create([
            'channel' => TemplateChannel::Sms,
            'name' => 'Sms nudge',
            'purpose' => TemplatePurpose::General,
        ]);
        // Long enough resolved body to assert segment count > 1 when needed; keep short for XOR.
        $this->family->variants()->create([
            'locale' => 'en',
            'body_text' => 'Hello {{contact.first_name}}, please call us about your unit.',
        ]);
    }

    public function test_xor_three_surfaces_segments(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Segment',
            'last_name' => 'Tester',
        ]);
        ContactChannel::query()->create([
            'contact_id' => $contact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15557654321',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $expectedBody = 'Hello Segment, please call us about your unit.';
        $expectedSegments = (new SmsMessage('+15557654321', $expectedBody))->segmentCount();

        // Surface 1: inbox reply with template_family_id XOR body.
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Sms,
            'channel_key' => '+15557654321',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => 0,
        ]);

        $xorFail = $this->postJson("/api/inbox/threads/{$thread->id}/reply", [
            'body_text' => 'inline',
            'template_family_id' => $this->family->id,
        ]);
        $xorFail->assertStatus(422);

        $reply = $this->postJson("/api/inbox/threads/{$thread->id}/reply", [
            'template_family_id' => $this->family->id,
        ]);
        $reply->assertCreated();
        $this->assertSame($expectedBody, Message::query()->findOrFail($reply->json('data.message.id'))->body_text);
        $this->assertSame($expectedSegments, $reply->json('data.segments'));

        // Surface 2: inbox compose.
        $composeXor = $this->postJson('/api/inbox/compose', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'body_text' => 'inline',
            'template_family_id' => $this->family->id,
        ]);
        $composeXor->assertStatus(422);

        $compose = $this->postJson('/api/inbox/compose', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'template_family_id' => $this->family->id,
        ]);
        $compose->assertCreated();
        $this->assertSame($expectedBody, Message::query()->findOrFail($compose->json('data.message.id'))->body_text);
        $this->assertSame($expectedSegments, $compose->json('data.segments'));

        // Surface 3: playbook send_sms template path via harness config rewrite.
        $harness = AutomationHarness::load('sms_send_and_skip_no_channel');
        $smsNode = $harness->automation()->nodes->firstWhere('node_key', 'sms');
        $this->assertNotNull($smsNode);
        $smsNode->update([
            'config' => [
                'bodyType' => 'template',
                'template_family_id' => $this->family->id,
                'tokens' => true,
            ],
        ]);

        $playContact = Contact::factory()->create([
            'first_name' => 'Segment',
            'last_name' => 'Before',
        ]);
        ContactChannel::query()->create([
            'contact_id' => $playContact->id,
            'type' => ContactChannelType::Phone,
            'value' => '+15557654322',
            'is_primary' => true,
            'opted_in' => true,
        ]);

        $harness->trigger('object_created', $playContact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('sms', AutomationRunStepStatus::Succeeded);

        $smsStep = $harness->run()->steps->first(
            fn ($step) => $step->node_id === $smsNode->id
        );
        $this->assertSame($expectedBody, $smsStep?->output['body'] ?? null);
        $this->assertSame($expectedSegments, $smsStep?->output['segments'] ?? null);
        $this->assertSame('SmsContinued', $playContact->fresh()->last_name);
    }
}
