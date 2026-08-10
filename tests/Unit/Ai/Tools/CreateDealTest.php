<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Tools;

use App\Ai\Tools\CreateDeal;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Auth\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class CreateDealTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    #[Test]
    public function creates_deal_when_employee_has_permission(): void
    {
        $employee = $this->employeeWithPermission(Permission::DealManage);
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateDeal($employee))->handle(new Request([
            'contact_id' => $contact->id,
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('deals', ['contact_id' => $contact->id]);
    }

    #[Test]
    public function denies_creation_when_employee_lacks_permission(): void
    {
        $employee = $this->employeeWithoutPermissions();
        $contact = Contact::factory()->create();

        $result = json_decode((new CreateDeal($employee))->handle(new Request([
            'contact_id' => $contact->id,
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertSame(0, Deal::query()->count());
    }
}
