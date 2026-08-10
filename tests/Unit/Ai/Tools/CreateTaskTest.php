<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateTask;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateTaskTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function adds_task_to_contact_when_employee_has_contact_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateTask($employee))->handle(new Request([
            'taskable_type' => 'contact',
            'taskable_id' => $contact->id,
            'title' => 'Call back tomorrow',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('tasks', ['taskable_type' => 'contact', 'taskable_id' => $contact->id]);
    }

    #[Test]
    public function denies_task_on_deal_without_deal_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $deal = Deal::factory()->create();

        $result = json_decode((new CreateTask($employee))->handle(new Request([
            'taskable_type' => 'deal',
            'taskable_id' => $deal->id,
            'title' => 'Send follow-up email',
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function rejects_unsupported_taskable_type(): void
    {
        $employee = $this->employeeWithPermission(Permission::OfferManage);

        foreach (['offer', 'reservation', 'contract'] as $type) {
            $result = json_decode((new CreateTask($employee))->handle(new Request([
                'taskable_type' => $type,
                'taskable_id' => 1,
                'title' => 'x',
            ])), true);

            $this->assertFalse($result['success'], "taskable_type '{$type}' must not be supported.");
        }

        $this->assertSame(0, Task::query()->count());
    }
}
