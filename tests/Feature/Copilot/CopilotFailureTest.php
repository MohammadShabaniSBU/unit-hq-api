<?php

declare(strict_types=1);

namespace Tests\Feature\Copilot;

use App\Ai\Agents\CrmCopilotAgent;
use App\Events\CopilotFailed;
use App\Listeners\BroadcastCopilotFailed;
use App\Models\CopilotConversation;
use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Jobs\BroadcastAgent;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class CopilotFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        config(['ai.conversations.generate_title' => false]);
    }

    #[Test]
    public function failed_job_broadcasts_terminal_event(): void
    {
        Event::fake([CopilotFailed::class]);

        $employee = Employee::factory()->manager()->create();

        $conversation = CopilotConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'employee',
            'participant_id' => $employee->id,
            'title' => 'Failure',
            'site_scope_snapshot' => null,
        ]);

        $agent = (new CrmCopilotAgent($employee))->continue($conversation->id, as: $employee);
        $broadcastJob = new BroadcastAgent(
            $agent,
            'This will fail',
            new PrivateChannel("copilot.{$conversation->id}"),
        );

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('payload')->andReturn([
            'data' => [
                'command' => serialize($broadcastJob),
            ],
        ]);

        (new BroadcastCopilotFailed)->handle(new JobFailed(
            'database',
            $queueJob,
            new RuntimeException('Agent timeout'),
        ));

        Event::assertDispatched(CopilotFailed::class, function (CopilotFailed $event) use ($conversation, $broadcastJob): bool {
            return $event->conversationId === $conversation->id
                && $event->callId === $broadcastJob->invocationId
                && $event->errorKey === 'copilot.stream.failed'
                && $event->broadcastAs() === 'copilot.failed';
        });
    }
}
