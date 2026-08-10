<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateContactAddress;
use App\Models\Contact;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateContactAddressTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function adds_address_when_employee_has_contact_manage(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateContactAddress($employee))->handle(new Request([
            'contact_id' => $contact->id,
            'type' => 'home',
            'city' => 'Springfield',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('contact_addresses', ['contact_id' => $contact->id, 'city' => 'Springfield']);
    }

    #[Test]
    public function denies_when_employee_lacks_contact_manage(): void
    {
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateContactAddress($employee))->handle(new Request([
            'contact_id' => $contact->id,
            'type' => 'home',
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertDatabaseMissing('contact_addresses', ['contact_id' => $contact->id]);
    }
}
