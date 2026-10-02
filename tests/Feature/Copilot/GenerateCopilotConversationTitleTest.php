<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CopilotTitleAgent;
use App\Events\CopilotConversationTitled;
use App\Jobs\GenerateCopilotConversationTitle;
use App\Models\CopilotConversation;
use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GenerateCopilotConversationTitleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function job_saves_title_and_broadcasts_on_employee_channel(): void
    {
        $employee = Employee::factory()->manager()->create();
        $conversation = $this->conversation($employee);

        Event::fake([CopilotConversationTitled::class]);
        CopilotTitleAgent::fake(['Recent contacts']);

        (new GenerateCopilotConversationTitle(
            $conversation->id,
            $employee->id,
            'Show me my recent contacts',
        ))->handle();

        $this->assertSame('Recent contacts', $conversation->fresh()->title);

        Event::assertDispatched(CopilotConversationTitled::class, function (CopilotConversationTitled $event) use ($employee, $conversation): bool {
            return $event->conversationId === $conversation->id
                && $event->employeeId === $employee->id
                && $event->title === 'Recent contacts'
                && $event->broadcastAs() === 'copilot.conversation.titled'
                && $event->broadcastOn()[0]->name === 'private-copilot-titles.'.$employee->id
                && $event->broadcastWith() === [
                    'conversation_id' => $conversation->id,
                    'title' => 'Recent contacts',
                ];
        });
    }

    #[Test]
    public function provider_failure_falls_back_to_truncated_message(): void
    {
        $employee = Employee::factory()->manager()->create();
        $message = 'Show me every contact created in the last thirty days';
        $conversation = $this->conversation($employee);

        Event::fake([CopilotConversationTitled::class]);
        CopilotTitleAgent::fake(fn () => throw new RuntimeException('provider down'));

        (new GenerateCopilotConversationTitle(
            $conversation->id,
            $employee->id,
            $message,
        ))->handle();

        $this->assertSame(Str::limit($message, 50, '...'), $conversation->fresh()->title);
        Event::assertDispatched(CopilotConversationTitled::class);
    }

    #[Test]
    public function titled_conversation_is_left_alone(): void
    {
        $employee = Employee::factory()->manager()->create();
        $conversation = $this->conversation($employee, 'Already named');

        Event::fake([CopilotConversationTitled::class]);
        CopilotTitleAgent::fake(['Should not run'])->preventStrayPrompts();

        (new GenerateCopilotConversationTitle(
            $conversation->id,
            $employee->id,
            'Show me my recent contacts',
        ))->handle();

        $this->assertSame('Already named', $conversation->fresh()->title);
        Event::assertNotDispatched(CopilotConversationTitled::class);
    }

    private function conversation(Employee $employee, string $title = CopilotConversation::UNTITLED): CopilotConversation
    {
        return CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => $title,
            'site_scope_snapshot' => null,
        ]);
    }
}
