<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateContactChannel;
use App\Models\Contact;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateContactChannelTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function adds_channel_when_employee_has_contact_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateContactChannel($employee))->handle(new Request([
            'contact_id' => $contact->id,
            'type' => 'email',
            'value' => 'jane@example.com',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('contact_channels', ['contact_id' => $contact->id, 'value' => 'jane@example.com']);
    }

    #[Test]
    public function denies_when_employee_lacks_contact_manage(): void
    {
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateContactChannel($employee))->handle(new Request([
            'contact_id' => $contact->id,
            'type' => 'email',
            'value' => 'jane@example.com',
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertDatabaseMissing('contact_channels', ['contact_id' => $contact->id]);
    }
}
