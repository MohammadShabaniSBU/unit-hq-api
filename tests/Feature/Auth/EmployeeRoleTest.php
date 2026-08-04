<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function company_role_rejects_site_id(): void
    {
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $owner = Role::query()->where('key', 'owner')->firstOrFail();
        $site = Site::factory()->create();

        $this->expectException(ValidationException::class);

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $owner->id,
            'site_id' => $site->id,
        ]);
    }

    #[Test]
    public function site_role_requires_site_id(): void
    {
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $siteManager = Role::query()->where('key', 'site_manager')->firstOrFail();

        $this->expectException(ValidationException::class);

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $siteManager->id,
            'site_id' => null,
        ]);
    }

    #[Test]
    public function duplicate_company_grant_rejected(): void
    {
        $employee = Employee::factory()->manager()->create();
        $owner = Role::query()->where('key', 'owner')->firstOrFail();

        $this->expectException(QueryException::class);

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $owner->id,
            'site_id' => null,
        ]);
    }

    #[Test]
    public function duplicate_site_grant_rejected(): void
    {
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $siteManager = Role::query()->where('key', 'site_manager')->firstOrFail();
        $site = Site::factory()->create();

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $siteManager->id,
            'site_id' => $site->id,
        ]);

        $this->expectException(QueryException::class);

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $siteManager->id,
            'site_id' => $site->id,
        ]);
    }
}
