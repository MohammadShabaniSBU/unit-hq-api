<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateNote;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Note;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateNoteTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function adds_note_to_contact_when_employee_has_contact_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateNote($employee))->handle(new Request([
            'notable_type' => 'contact',
            'notable_id' => $contact->id,
            'content' => 'Called about pricing.',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('notes', ['notable_type' => 'contact', 'notable_id' => $contact->id]);
    }

    #[Test]
    public function adding_a_deal_note_requires_deal_manage_not_contact_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $deal = Deal::factory()->create();

        $result = json_decode((new CreateNote($employee))->handle(new Request([
            'notable_type' => 'deal',
            'notable_id' => $deal->id,
            'content' => 'Following up next week.',
        ])), true);

        $this->assertFalse($result['success'], 'ContactManage alone must not authorize a note on a Deal.');
        $this->assertSame(0, Note::query()->count());
    }

    #[Test]
    public function adds_note_to_deal_when_employee_has_deal_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::DealManage);
        $deal = Deal::factory()->create();

        $result = json_decode((new CreateNote($employee))->handle(new Request([
            'notable_type' => 'deal',
            'notable_id' => $deal->id,
            'content' => 'Following up next week.',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('notes', ['notable_type' => 'deal', 'notable_id' => $deal->id]);
    }

    #[Test]
    public function rejects_unsupported_notable_type(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);

        $result = json_decode((new CreateNote($employee))->handle(new Request([
            'notable_type' => 'invoice',
            'notable_id' => 1,
            'content' => 'x',
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Note::query()->count());
    }
}
