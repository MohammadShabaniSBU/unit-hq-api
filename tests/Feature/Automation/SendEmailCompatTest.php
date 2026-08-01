<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunStatus;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AutomationHarness;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\TestCase;

class SendEmailCompatTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        $this->fakeCommunicationProviders();
        $this->seedEmailAccount(Site::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_legacy_params(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Legacy',
            'last_name' => 'Params',
            'email' => 'legacy-'.uniqid().'@example.com',
        ]);
        $this->givePrimaryEmail($contact, 'legacy-primary@example.com');

        AutomationHarness::load('linear_three_actions')
            ->trigger('object_created', $contact)
            ->assertRunStatus(AutomationRunStatus::Succeeded)
            ->assertStepSequence([
                'trigger',
                'update_one',
                'email',
                'update_two',
            ]);

        $contact->refresh();
        $this->assertSame('LinearOne', $contact->first_name);
        $this->assertSame('LinearTwo', $contact->last_name);

        $interaction = Interaction::query()
            ->where('contact_id', $contact->id)
            ->where('channel', 'email')
            ->first();
        $this->assertNotNull($interaction);
        $this->assertSame('Linear hello', $interaction->summary);
        $this->assertSame('Linear body', $interaction->content);
    }
}
