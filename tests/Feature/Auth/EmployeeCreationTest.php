<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\Role;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $owner;

    private Role $opsRole;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        $this->owner = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->owner);
        $this->opsRole = Role::query()->where('key', 'operations_manager')->firstOrFail();
    }

    #[Test]
    public function creates_employee_with_grants_and_invitation(): void
    {
        $response = $this->postJson('/api/employees', [
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
            'email' => 'jamie@example.com',
            'grants' => [
                ['role_id' => $this->opsRole->id, 'site_id' => null],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'jamie@example.com')
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.first_name', 'Jamie')
            ->assertJsonStructure(['data' => ['invite_link', 'email_sent', 'grants']]);

        $employee = Employee::query()->where('email', 'jamie@example.com')->firstOrFail();
        $this->assertNull($employee->password);
        $this->assertSame(1, $employee->employeeRoles()->count());
        $this->assertSame(1, EmployeeInvitation::query()->where('employee_id', $employee->id)->count());
        $this->assertStringContainsString('/invite/', $response->json('data.invite_link'));
    }

    #[Test]
    public function returns_link_when_comms_unconfigured(): void
    {
        $response = $this->postJson('/api/employees', [
            'first_name' => 'No',
            'last_name' => 'Mail',
            'email' => 'nomail@example.com',
            'grants' => [],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email_sent', false);

        $link = $response->json('data.invite_link');
        $this->assertIsString($link);
        $this->assertStringContainsString('/invite/', $link);
    }

    #[Test]
    public function rejects_duplicate_email_case_insensitively(): void
    {
        Employee::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/employees', [
            'first_name' => 'Dup',
            'last_name' => 'Case',
            'email' => 'Dup@Example.com',
            'grants' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
