<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Message;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AutomationHarness;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class SendFloorTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        $this->fakeCommunicationProviders();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_primary_channel_only_and_interaction_written(): void
    {
        $site = Site::factory()->create();
        $emailAccount = $this->seedEmailAccount($site);
        $smsAccount = $this->seedSmsAccount($site);

        $contact = Contact::factory()->create([
            'first_name' => 'Floor',
            'last_name' => 'Contact',
            'email' => 'ignored-legacy@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'primary-floor@example.com');
        $this->givePrimaryPhone($contact, '+15551234567');

        // Email via legacy inline fixture shape — recipient must be primary channel.
        AutomationHarness::load('linear_three_actions')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded);

        $emailInteraction = Interaction::query()
            ->where('contact_id', $contact->id)
            ->where('channel', 'email')
            ->first();
        $this->assertNotNull($emailInteraction);
        $this->assertSame($emailAccount->id, $emailInteraction->communication_account_id);
        $this->assertSame('brevo-test-1', $emailInteraction->provider_message_id);
        $this->assertSame('automation', $emailInteraction->metadata['source'] ?? null);
        $this->assertNotNull($emailInteraction->message_id);
        $this->assertTrue(Message::query()->whereKey($emailInteraction->message_id)->exists());

        // SMS happy path + sequence continues.
        $smsContact = Contact::factory()->create([
            'first_name' => 'SmsFloor',
            'last_name' => 'Before',
            'email' => 'sms-floor-'.uniqid().'@example.com',
        ]);
        $this->givePrimaryPhone($smsContact, '+15557654321');

        AutomationHarness::load('sms_send_and_skip_no_channel')
            ->trigger('object_created', $smsContact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('sms', AutomationRunStepStatus::Succeeded)
            ->assertStepStatus('after', AutomationRunStepStatus::Succeeded);

        $this->assertSame('SmsContinued', $smsContact->fresh()->last_name);

        $smsInteraction = Interaction::query()
            ->where('contact_id', $smsContact->id)
            ->where('channel', 'sms')
            ->first();
        $this->assertNotNull($smsInteraction);
        $this->assertSame($smsAccount->id, $smsInteraction->communication_account_id);
        $this->assertSame('SM-test-1', $smsInteraction->provider_message_id);
        $this->assertNotNull($smsInteraction->message_id);
        $this->assertTrue(Message::query()->whereKey($smsInteraction->message_id)->exists());

        // Missing phone → skip-with-reason; sequence continues.
        $noPhone = Contact::factory()->create([
            'first_name' => 'NoPhone',
            'last_name' => 'Before',
            'email' => 'nophone-'.uniqid().'@example.com',
        ]);

        $harness = AutomationHarness::load('sms_send_and_skip_no_channel')
            ->trigger('object_created', $noPhone)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepStatus('sms', AutomationRunStepStatus::Succeeded)
            ->assertStepStatus('after', AutomationRunStepStatus::Succeeded);

        $smsStep = $harness->run()->steps->first(
            fn ($step) => $step->node_id === $harness->automation()->nodes->firstWhere('node_key', 'sms')?->id
        );
        $this->assertSame('no_channel', $smsStep?->output['skipped_reason'] ?? null);
        $this->assertSame('SmsContinued', $noPhone->fresh()->last_name);
        $this->assertSame(
            0,
            Interaction::query()->where('contact_id', $noPhone->id)->where('channel', 'sms')->count(),
        );

        // Missing email channel → skip; legacy contacts.email must not be used.
        $noEmailChannel = Contact::factory()->create([
            'first_name' => 'NoEmailCh',
            'last_name' => 'Contact',
            'email' => 'not-a-channel@example.com',
        ]);

        $emailHarness = AutomationHarness::load('linear_three_actions')
            ->trigger('object_created', $noEmailChannel)
            ->assertRunStatus(AutomationRunStatus::Succeeded);

        $emailStep = $emailHarness->run()->steps->first(
            fn ($step) => $step->node_id === $emailHarness->automation()->nodes->firstWhere('node_key', 'email')?->id
        );
        $this->assertSame('no_channel', $emailStep?->output['skipped_reason'] ?? null);
        $this->assertSame(
            0,
            Interaction::query()->where('contact_id', $noEmailChannel->id)->where('channel', 'email')->count(),
        );
    }
}
