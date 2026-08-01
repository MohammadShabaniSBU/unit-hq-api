<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class AssignTest extends TestCase
{
    use RefreshDatabase;
    use SeedsInboxThreads;

    public function test_audit_and_stopgap(): void
    {
        $manager = Employee::factory()->manager()->create();
        $assignee = Employee::factory()->staff()->create(['name' => 'Desk Staff']);
        $contact = Contact::factory()->create();
        $thread = $this->makeInboxThread($contact, [
            'subject' => 'Needs owner',
            'last_message_at' => now(),
        ]);

        // Stopgap: unauthenticated is rejected (auth:sanctum only until S17).
        $this->postJson("/api/inbox/threads/{$thread->id}/assign", [
            'employee_id' => $assignee->id,
        ])->assertUnauthorized();

        Sanctum::actingAs($manager);

        $this->postJson("/api/inbox/threads/{$thread->id}/assign", [
            'employee_id' => $assignee->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.assigned_employee.id', $assignee->id)
            ->assertJsonPath('data.assigned_employee.name', 'Desk Staff');

        $thread->refresh();
        $this->assertSame($assignee->id, $thread->assigned_employee_id);

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Comms->value)
                ->where(function ($q): void {
                    $q->where('event', 'thread.assigned')
                        ->orWhere('description', 'thread.assigned');
                })
                ->exists(),
        );

        $this->postJson("/api/inbox/threads/{$thread->id}/assign", [
            'employee_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.assigned_employee', null);

        $thread->refresh();
        $this->assertNull($thread->assigned_employee_id);

        $this->getJson('/api/inbox/threads?filter=unassigned')
            ->assertOk()
            ->assertJsonFragment(['id' => $thread->id]);
    }
}
