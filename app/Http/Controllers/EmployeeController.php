<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\OwnerFloor;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $employees = Employee::query()
            ->with(['employeeRoles.role', 'employeeRoles.site'])
            ->orderBy('name')
            ->get()
            ->map(static function (Employee $employee): array {
                $grants = $employee->employeeRoles
                    ->filter(static fn (EmployeeRole $grant): bool => $grant->role !== null && ! $grant->role->isArchived())
                    ->map(static fn (EmployeeRole $grant): array => self::serializeGrant($grant))
                    ->values()
                    ->all();

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'grants' => $grants,
                ];
            })
            ->all();

        return $this->success($employees, 'Employees retrieved successfully.');
    }

    public function roles(Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $employee->load(['employeeRoles.role', 'employeeRoles.site']);

        $grants = $employee->employeeRoles
            ->map(static fn (EmployeeRole $grant): array => self::serializeGrant($grant))
            ->values()
            ->all();

        return $this->success($grants, 'Employee roles retrieved successfully.');
    }

    public function storeRole(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('archived_at')],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $role = Role::query()->findOrFail($validated['role_id']);

        $grant = new EmployeeRole([
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'site_id' => $validated['site_id'] ?? null,
            'granted_by' => $request->user()?->id,
        ]);
        $grant->setRelation('role', $role);
        $grant->save();

        $employee->forgetPermissionMap();
        $grant->load(['role', 'site']);

        return $this->created(self::serializeGrant($grant), 'Role granted successfully.');
    }

    public function destroyRole(Employee $employee, EmployeeRole $grant): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ((int) $grant->employee_id !== (int) $employee->id) {
            throw ValidationException::withMessages([
                'grant' => [__('errors.rbac.grant_mismatch')],
            ]);
        }

        OwnerFloor::revoke($grant);
        $employee->forgetPermissionMap();

        return $this->success(null, 'Role grant removed successfully.');
    }

    public function options(): JsonResponse
    {
        Gate::authorize(Permission::InboxAssign->value);

        $options = Employee::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->name,
            ]);

        return $this->success($options, 'Employee options retrieved successfully.');
    }

    /**
     * @return array{
     *     id: int,
     *     role_id: int,
     *     role_key: string,
     *     role_label: string,
     *     scope_level: string,
     *     site_id: int|null,
     *     site_name: string|null,
     *     is_company_wide: bool
     * }
     */
    private static function serializeGrant(EmployeeRole $grant): array
    {
        $role = $grant->role;
        $site = $grant->site;

        return [
            'id' => $grant->id,
            'role_id' => $grant->role_id,
            'role_key' => $role?->key ?? '',
            'role_label' => $role?->label ?? '',
            'scope_level' => $role?->scope_level->value ?? '',
            'site_id' => $grant->site_id !== null ? (int) $grant->site_id : null,
            'site_name' => $site instanceof Site ? $site->name : null,
            'is_company_wide' => $grant->site_id === null,
        ];
    }
}
