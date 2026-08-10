<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateContact;
use App\Models\Contact;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateContactTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function creates_contact_when_employee_has_permission(): void
    {
        $employee = $this->employeeWithPermission(Permission::ContactManage);

        $result = json_decode((new CreateContact($employee))->handle(new Request([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('contacts', ['email' => 'ada@example.com']);
    }

    #[Test]
    public function denies_creation_when_employee_lacks_permission(): void
    {
        $employee = $this->employeeWithoutPermissions();

        $result = json_decode((new CreateContact($employee))->handle(new Request([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertDatabaseMissing('contacts', ['email' => 'ada@example.com']);
        $this->assertSame(0, Contact::query()->count());
    }
}
