<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Site;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;

/**
 * Builds an ad hoc, company-scoped role granting exactly one permission —
 * for tests that need "an employee who can X" without pulling in a full
 * seeded system role (which grants a whole bundle of permissions at once).
 */
trait GrantsSinglePermission
{
    protected function employeeWithPermission(Permission $permission): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-'.$permission->value.'-'.uniqid(),
            'label' => 'Test: '.$permission->value,
            'scope_level' => RoleScope::Company,
            'is_system' => false,
        ]);

        RolePermission::query()->create([
            'role_id' => $role->id,
            'permission' => $permission->value,
        ]);

        $employee = Employee::factory()->withoutRoleGrant()->create();

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => null,
        ]);

        return $employee;
    }

    protected function employeeWithoutPermissions(): Employee
    {
        return Employee::factory()->withoutRoleGrant()->create();
    }

    protected function employeeWithSiteScopedPermission(Permission $permission, Site $site): Employee
    {
        $role = Role::query()->create([
            'key' => 'test-'.$permission->value.'-site-'.uniqid(),
            'label' => 'Test: '.$permission->value.' at one site',
            'scope_level' => RoleScope::Site,
            'is_system' => false,
        ]);

        RolePermission::query()->create([
            'role_id' => $role->id,
            'permission' => $permission->value,
        ]);

        $employee = Employee::factory()->withoutRoleGrant()->create();

        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => $site->id,
        ]);

        return $employee;
    }
}
