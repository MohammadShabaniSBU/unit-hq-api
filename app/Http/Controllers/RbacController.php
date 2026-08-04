<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\Auth\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RbacController extends Controller
{
    /**
     * Permission enum grouped by domain (for the role editor).
     */
    public function permissions(): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $grouped = [];

        foreach (Permission::cases() as $permission) {
            $domain = $permission->domain();
            $grouped[$domain] ??= [];
            $grouped[$domain][] = [
                'permission' => $permission->value,
                'i18n_key' => $permission->i18nKey(),
            ];
        }

        ksort($grouped);

        return $this->success($grouped);
    }

    /**
     * Active roles with their permission lists.
     */
    public function roles(): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $roles = Role::query()
            ->active()
            ->with('rolePermissions')
            ->orderBy('key')
            ->get()
            ->map(static function (Role $role): array {
                $permissions = $role->rolePermissions
                    ->map(static fn ($rp): string => $rp->permission instanceof Permission
                        ? $rp->permission->value
                        : (string) $rp->permission)
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'id' => $role->id,
                    'key' => $role->key,
                    'label' => $role->label,
                    'description' => $role->description,
                    'scope_level' => $role->scope_level->value,
                    'is_system' => $role->is_system,
                    'permissions' => $permissions,
                ];
            })
            ->all();

        return $this->success($roles);
    }
}
