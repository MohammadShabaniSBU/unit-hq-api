<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\Role;
use Database\Factories\EmployeeFactory;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        // Keep an owner so floor is satisfied when staff has no RbacManage.
        Employee::factory()->manager()->create();
    }

    #[Test]
    public function employee_changes_own_password_without_rbac_manage(): void
    {
        $staff = Employee::factory()->withoutRoleGrant()->create([
            'password' => 'old-password-12',
        ]);
        EmployeeFactory::grantCompanyRole($staff, 'read_only');
        Sanctum::actingAs($staff->fresh());

        $this->postJson('/api/user/password', [
            'current_password' => 'old-password-12',
            'password' => 'new-password-12',
        ])->assertOk();

        $staff->refresh();
        $this->assertTrue(Hash::check('new-password-12', $staff->password));
    }

    #[Test]
    public function employee_cannot_change_own_grants(): void
    {
        $staff = Employee::factory()->withoutRoleGrant()->create();
        EmployeeFactory::grantCompanyRole($staff, 'read_only');
        Sanctum::actingAs($staff->fresh());

        $roleId = (int) Role::query()->where('key', 'operations_manager')->value('id');

        $this->postJson("/api/employees/{$staff->id}/roles", [
            'role_id' => $roleId,
            'site_id' => null,
        ])->assertForbidden();
    }
}
