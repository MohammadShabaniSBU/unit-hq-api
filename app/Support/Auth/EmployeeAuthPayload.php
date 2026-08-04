<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Employee;
use App\Models\Role;

/**
 * Builds the /api/user (and login employee) payload shape for RBAC.
 */
final class EmployeeAuthPayload
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     role: string|null,
     *     roles: list<array{key: string, label: string, site_id: int|null}>,
     *     permissions: list<array{permission: string, site_ids: list<int>|null}>,
     *     company_permissions: list<string>
     * }
     */
    public static function for(Employee $employee): array
    {
        $employee->loadMissing(['employeeRoles.role.rolePermissions']);

        $roles = [];
        foreach ($employee->employeeRoles as $grant) {
            $role = $grant->role;
            if ($role === null || $role->isArchived()) {
                continue;
            }

            $roles[] = [
                'key' => $role->key,
                'label' => $role->label,
                'site_id' => $grant->site_id !== null ? (int) $grant->site_id : null,
            ];
        }

        $permissionSites = PermissionMap::for($employee);
        $companyPermissions = [];
        $permissions = [];

        foreach ($permissionSites as $permission => $siteIds) {
            if ($siteIds === null) {
                $companyPermissions[] = $permission;
                $permissions[] = [
                    'permission' => $permission,
                    'site_ids' => null,
                ];
            } else {
                $permissions[] = [
                    'permission' => $permission,
                    'site_ids' => $siteIds,
                ];
            }
        }

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            /** @deprecated Keep for one sprint; delete in task 05. */
            'role' => self::deprecatedRoleKey($roles),
            'roles' => $roles,
            'permissions' => $permissions,
            'company_permissions' => $companyPermissions,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, site_id: int|null}>  $roles
     */
    private static function deprecatedRoleKey(array $roles): ?string
    {
        $rank = Role::systemKeyRank();
        $bestKey = null;
        $bestRank = -1;

        foreach ($roles as $role) {
            $key = $role['key'];
            $r = $rank[$key] ?? 0;
            if ($r > $bestRank) {
                $bestRank = $r;
                $bestKey = $key;
            }
        }

        return $bestKey;
    }
}
