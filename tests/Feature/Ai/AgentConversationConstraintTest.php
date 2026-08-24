<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentHandoff;
use App\Models\AgentToolInvocation;
use App\Models\AgentWritePolicy;
use App\Models\AiAgent;
use App\Models\Concerns\HasAutomationTriggers;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Ai\Enums\AgentAudience;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Ai\Enums\AgentOrigin;
use App\Support\Ai\Enums\ConversationState;
use App\Support\Ai\Enums\VerificationLevel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentConversationConstraintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function valid_customer_demo_verified_row_inserts(): void
    {
        $conversation = AgentConversation::factory()->create();

        $this->assertTrue($conversation->exists);
        $this->assertSame(AgentAudience::Customer, $conversation->audience);
        $this->assertSame(AgentOrigin::Demo, $conversation->origin);
        $this->assertSame(VerificationLevel::Verified, $conversation->verification_level);
        $this->assertNotNull($conversation->contact_id);
        $this->assertNotNull($conversation->created_by_employee_id);
    }

    #[Test]
    public function internal_with_contact_is_rejected(): void
    {
        $agent = AiAgent::factory()->create();
        $employee = Employee::factory()->create();
        $contact = Contact::factory()->create();

        $this->expectException(QueryException::class);

        AgentConversation::query()->create([
            'ai_agent_id' => $agent->id,
            'audience' => AgentAudience::Internal,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Internal,
            'employee_id' => $employee->id,
            'created_by_employee_id' => $employee->id,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::Verified,
            'state' => ConversationState::Active,
        ]);
    }

    #[Test]
    public function customer_with_employee_principal_is_rejected(): void
    {
        $agent = AiAgent::factory()->create();
        $employee = Employee::factory()->create();
        $contact = Contact::factory()->create();

        $this->expectException(QueryException::class);

        AgentConversation::query()->create([
            'ai_agent_id' => $agent->id,
            'audience' => AgentAudience::Customer,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Webchat,
            'employee_id' => $employee->id,
            'created_by_employee_id' => $employee->id,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::Verified,
            'state' => ConversationState::Active,
        ]);
    }

    #[Test]
    public function verified_without_contact_is_rejected(): void
    {
        $agent = AiAgent::factory()->create();
        $employee = Employee::factory()->create();

        $this->expectException(QueryException::class);

        AgentConversation::query()->create([
            'ai_agent_id' => $agent->id,
            'audience' => AgentAudience::Customer,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Webchat,
            'employee_id' => null,
            'created_by_employee_id' => $employee->id,
            'contact_id' => null,
            'verification_level' => VerificationLevel::Verified,
            'state' => ConversationState::Active,
        ]);
    }

    #[Test]
    public function demo_without_creator_is_rejected(): void
    {
        $agent = AiAgent::factory()->create();
        $contact = Contact::factory()->create();

        $this->expectException(QueryException::class);

        AgentConversation::query()->create([
            'ai_agent_id' => $agent->id,
            'audience' => AgentAudience::Customer,
            'origin' => AgentOrigin::Demo,
            'channel' => AgentChannel::Webchat,
            'employee_id' => null,
            'created_by_employee_id' => null,
            'contact_id' => $contact->id,
            'verification_level' => VerificationLevel::Verified,
            'state' => ConversationState::Active,
        ]);
    }

    #[Test]
    public function agent_models_do_not_use_automation_triggers(): void
    {
        foreach ([
            AiAgent::class,
            AgentConversation::class,
            AgentConversationMessage::class,
            AgentToolInvocation::class,
            AgentHandoff::class,
            AgentWritePolicy::class,
        ] as $class) {
            $this->assertNotContains(
                HasAutomationTriggers::class,
                class_uses_recursive($class),
                "{$class} must not use HasAutomationTriggers",
            );
        }
    }
}
