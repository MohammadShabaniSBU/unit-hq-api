<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * Roles with their permission lists. Filter via ?status=active|archived|all.
     */
    public function roles(Request $request): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'archived', 'all'])],
        ]);

        $query = Role::query()
            ->with('rolePermissions')
            ->orderBy('key');

        $status = $validated['status'] ?? 'active';

        match ($status) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        $roles = $query->get()
            ->map(static fn (Role $role): array => self::serializeRole($role))
            ->all();

        return $this->success($roles);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,key'],
            'label' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'scope_level' => ['required', Rule::enum(RoleScope::class)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::enum(Permission::class)],
        ]);

        $role = DB::transaction(function () use ($validated): Role {
            $role = Role::query()->create([
                'key' => $validated['key'],
                'label' => $validated['label'],
                'description' => $validated['description'] ?? null,
                'scope_level' => $validated['scope_level'],
                'is_system' => false,
            ]);

            self::syncPermissions($role, $validated['permissions']);

            return $role->fresh()->load('rolePermissions');
        });

        return $this->created(self::serializeRole($role), 'Role created successfully.');
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => [__('errors.rbac.system_role_immutable')],
            ]);
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::enum(Permission::class)],
        ]);

        $role = DB::transaction(function () use ($role, $validated): Role {
            $attrs = [];
            if (array_key_exists('label', $validated)) {
                $attrs['label'] = $validated['label'];
            }
            if (array_key_exists('description', $validated)) {
                $attrs['description'] = $validated['description'];
            }
            if ($attrs !== []) {
                $role->update($attrs);
            }

            if (array_key_exists('permissions', $validated)) {
                self::syncPermissions($role, $validated['permissions']);
            }

            return $role->fresh()->load('rolePermissions');
        });

        return $this->success(self::serializeRole($role), 'Role updated successfully.');
    }

    public function archive(Role $role): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => [__('errors.rbac.system_role_archive')],
            ]);
        }

        if ($role->isArchived()) {
            return $this->success(self::serializeRole($role->load('rolePermissions')), 'Role is already archived.');
        }

        $role->update(['archived_at' => now()]);

        return $this->success(
            self::serializeRole($role->fresh()->load('rolePermissions')),
            'Role archived successfully.',
        );
    }

    public function unarchive(Role $role): JsonResponse
    {
        Gate::authorize(Permission::RbacManage->value);

        if (! $role->isArchived()) {
            return $this->success(self::serializeRole($role->load('rolePermissions')), 'Role is already active.');
        }

        $role->update(['archived_at' => null]);

        return $this->success(
            self::serializeRole($role->fresh()->load('rolePermissions')),
            'Role unarchived successfully.',
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function syncPermissions(Role $role, array $permissions): void
    {
        $unique = array_values(array_unique($permissions));

        RolePermission::query()->where('role_id', $role->id)->delete();

        $now = now();
        RolePermission::query()->insert(array_map(
            static fn (string $permission): array => [
                'role_id' => $role->id,
                'permission' => $permission,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $unique,
        ));
    }

    /**
     * @return array{
     *     id: int,
     *     key: string,
     *     label: string,
     *     description: string|null,
     *     scope_level: string,
     *     is_system: bool,
     *     archived_at: string|null,
     *     permissions: list<string>,
     *     permission_count: int
     * }
     */
    private static function serializeRole(Role $role): array
    {
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
            'archived_at' => $role->archived_at?->toISOString(),
            'permissions' => $permissions,
            'permission_count' => count($permissions),
        ];
    }
}
