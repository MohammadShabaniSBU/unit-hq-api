<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Employee;

/**
 * Builds the per-employee permission → site_ids map from role grants.
 * site_ids null = company-wide; list = allowed sites only.
 *
 * @phpstan-type PermissionSitesMap array<string, list<int>|null>
 */
final class PermissionMap
{
    /**
     * @return PermissionSitesMap
     */
    public static function for(Employee $employee): array
    {
        $employee->loadMissing(['employeeRoles.role.rolePermissions']);

        /** @var PermissionSitesMap $permissionSites */
        $permissionSites = [];

        foreach ($employee->employeeRoles as $grant) {
            $role = $grant->role;
            if ($role === null || $role->isArchived()) {
                continue;
            }

            foreach ($role->rolePermissions as $rolePermission) {
                $perm = $rolePermission->permission;
                $value = $perm instanceof Permission ? $perm->value : (string) $perm;

                if ($grant->site_id === null) {
                    $permissionSites[$value] = null;

                    continue;
                }

                if (array_key_exists($value, $permissionSites) && $permissionSites[$value] === null) {
                    continue;
                }

                $siteId = (int) $grant->site_id;
                $existing = $permissionSites[$value] ?? [];
                if (! in_array($siteId, $existing, true)) {
                    $existing[] = $siteId;
                }
                $permissionSites[$value] = $existing;
            }
        }

        foreach ($permissionSites as $permission => $siteIds) {
            if ($siteIds !== null) {
                sort($siteIds);
                $permissionSites[$permission] = $siteIds;
            }
        }

        ksort($permissionSites);

        return $permissionSites;
    }
}
