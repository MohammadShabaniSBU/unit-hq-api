<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_deal_contact_full_name(): void
    {
        $employee = Employee::factory()->create();
        $contact = Contact::factory()->create([
            'first_name' => 'Gracia',
            'last_name' => 'Lin',
        ]);
        $deal = Deal::factory()->create(['contact_id' => $contact->id]);

        $task = Task::query()->create([
            'taskable_type' => 'deal',
            'taskable_id' => $deal->id,
            'created_by' => $employee->id,
            'title' => 'Call the lead',
            'priority' => 'medium',
            'status' => 'open',
        ]);

        $this->assertTrue(Task::query()->search('Gracia Lin')->whereKey($task->id)->exists());
        $this->assertFalse(Task::query()->search('Bea Torres')->whereKey($task->id)->exists());

        $task->load('taskable.contact');
        $this->assertSame('Gracia Lin', $task->taskablePayload()['label'] ?? null);
    }
}
