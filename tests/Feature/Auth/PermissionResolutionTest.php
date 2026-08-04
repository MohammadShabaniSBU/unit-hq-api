<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function company_grant_allows_any_site(): void
    {
        $employee = Employee::factory()->manager()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        $this->assertTrue($employee->can(Permission::ContractVacate, $siteA));
        $this->assertTrue($employee->can(Permission::ContractVacate, $siteB));
        $this->assertTrue($employee->can(Permission::ContractVacate));
    }

    #[Test]
    public function site_grant_denies_other_site(): void
    {
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $leasingAgent = Role::query()->where('key', 'leasing_agent')->firstOrFail();

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $leasingAgent->id,
            'site_id' => $siteA->id,
        ]);

        $employee->forgetPermissionMap();

        $this->assertTrue($employee->can(Permission::ContractView, $siteA));
        $this->assertFalse($employee->can(Permission::ContractView, $siteB));
        $this->assertTrue($employee->can(Permission::ContractView));
        $this->assertFalse($employee->can(Permission::ContractVacate, $siteA));
    }

    #[Test]
    public function revocation_applies_next_request(): void
    {
        // Keep a company owner so OwnerFloor allows revoking the site grant holder.
        Employee::factory()->manager()->create();

        $employee = Employee::factory()->withoutRoleGrant()->create();
        $site = Site::factory()->create();
        $leasingAgent = Role::query()->where('key', 'leasing_agent')->firstOrFail();

        $grant = EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $leasingAgent->id,
            'site_id' => $site->id,
        ]);

        $this->assertTrue(
            Employee::query()->findOrFail($employee->id)->can(Permission::ContractView, $site),
        );

        $grant->delete();

        $this->assertFalse(
            Employee::query()->findOrFail($employee->id)->can(Permission::ContractView, $site),
        );
    }

    #[Test]
    public function permissions_absent_from_token_abilities(): void
    {
        $employee = Employee::factory()->manager()->create();
        $newToken = $employee->createToken('panel');
        $abilities = $newToken->accessToken->abilities ?? [];

        foreach (Permission::cases() as $permission) {
            $this->assertNotContains(
                $permission->value,
                $abilities,
                "Permission {$permission->value} must not be baked into Sanctum token abilities",
            );
        }

        $loginSource = file_get_contents(app_path('Http/Controllers/EmployeeAuthController.php'));
        $this->assertIsString($loginSource);
        $this->assertStringNotContainsString(
            'Permission::',
            $loginSource,
        );
        $this->assertMatchesRegularExpression(
            "/createToken\\(\\s*'panel'\\s*\\)/",
            $loginSource,
        );
    }
}
