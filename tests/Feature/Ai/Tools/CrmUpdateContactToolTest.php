<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Enums\ContactSource;
use App\Enums\LogChannel;
use App\Models\Contact;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolErrorCode;
use App\Support\Ai\Enums\ToolInvocationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Ai\DispatchesAgentTools;
use Tests\TestCase;

class CrmUpdateContactToolTest extends TestCase
{
    use DispatchesAgentTools;
    use RefreshDatabase;

    #[Test]
    public function fills_an_empty_last_name(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Mohammed',
            'last_name' => '',
            'source' => ContactSource::AiAgent,
        ]);
        $principal = AgentPrincipal::channelAsserted($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        $result = $this->dispatchTool('concierge', 'crm.update_contact', $principal, [
            'last_name' => 'Chavani',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('Updated the contact name to Mohammed Chavani.', $result->display);
        $this->assertSame($contact->id, $result->data['contact_id']);
        $this->assertSame('Chavani', $contact->fresh()->last_name);
        $this->assertSame('Mohammed', $contact->fresh()->first_name);
        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Crm->value)
                ->where('description', 'contact.updated')
                ->where('subject_id', $contact->id)
                ->exists(),
        );
    }

    #[Test]
    public function overwrites_a_last_name(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'source' => ContactSource::AiAgent,
        ]);
        $principal = AgentPrincipal::channelAsserted($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        $result = $this->dispatchTool('concierge', 'crm.update_contact', $principal, [
            'last_name' => 'Hopper',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('Hopper', $contact->fresh()->last_name);
        $this->assertSame('Ada', $contact->fresh()->first_name);
    }

    #[Test]
    public function refuses_without_a_contact(): void
    {
        $principal = AgentPrincipal::anonymous(null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        $result = $this->dispatchTool('concierge', 'crm.update_contact', $principal, [
            'last_name' => 'Chavani',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::Unavailable, $result->error?->errorCode);
        $this->assertSame('crm.create_contact', $result->error?->recovery['tool'] ?? null);
        $this->assertSame(0, Contact::query()->count());
    }

    #[Test]
    public function refuses_empty_args(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $principal = AgentPrincipal::channelAsserted($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        $result = $this->dispatchTool('concierge', 'crm.update_contact', $principal, [], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertSame('Lovelace', $contact->fresh()->last_name);
    }

    #[Test]
    public function does_not_change_email(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ada',
            'last_name' => '',
            'email' => 'ada@example.com',
        ]);
        $principal = AgentPrincipal::channelAsserted($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        $result = $this->dispatchTool('concierge', 'crm.update_contact', $principal, [
            'last_name' => 'Lovelace',
            'email' => 'changed@example.com',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Ok, $result->status);
        $this->assertSame('ada@example.com', $contact->fresh()->email);
        $this->assertSame('Lovelace', $contact->fresh()->last_name);
    }

    #[Test]
    public function second_create_contact_is_still_invalid_arguments(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Mohammed',
            'last_name' => '',
            'source' => ContactSource::AiAgent,
        ]);
        $principal = AgentPrincipal::channelAsserted($contact->id, null, 'en');
        $ctx = $this->writeContext($principal, 'concierge');

        $this->dispatchTool('concierge', 'crm.update_contact', $principal, [
            'last_name' => 'Chavani',
        ], $ctx);

        $result = $this->dispatchTool('concierge', 'crm.create_contact', $principal, [
            'first_name' => 'Other',
            'last_name' => 'Person',
        ], $ctx);

        $this->assertSame(ToolInvocationStatus::Error, $result->status);
        $this->assertSame(ToolErrorCode::InvalidArguments, $result->error?->errorCode);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame('Chavani', $contact->fresh()->last_name);
    }
}
