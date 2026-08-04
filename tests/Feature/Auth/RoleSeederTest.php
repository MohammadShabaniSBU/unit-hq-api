<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Auth\Permission;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function is_idempotent(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $snapshot = static function (): array {
            return [
                'roles' => Role::query()->orderBy('key')->get(['id', 'key', 'label', 'scope_level', 'is_system'])->toArray(),
                'perms' => RolePermission::query()
                    ->orderBy('role_id')
                    ->orderBy('permission')
                    ->get(['id', 'role_id', 'permission'])
                    ->map(static fn (RolePermission $rp): array => [
                        'id' => $rp->id,
                        'role_id' => $rp->role_id,
                        'permission' => $rp->permission instanceof Permission
                            ? $rp->permission->value
                            : (string) $rp->permission,
                    ])
                    ->all(),
            ];
        };

        $before = $snapshot();
        RbacSystemRoleSeeder::upsertSystemRoles();
        $after = $snapshot();

        $this->assertSame($before, $after);
    }

    #[Test]
    public function repairs_drifted_system_role(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $ops = Role::query()->where('key', 'operations_manager')->firstOrFail();
        $ops->rolePermissions()->where('permission', Permission::ContactView->value)->delete();
        RolePermission::query()->create([
            'role_id' => $ops->id,
            'permission' => Permission::RbacManage->value,
        ]);

        RbacSystemRoleSeeder::upsertSystemRoles();

        $restored = $ops->fresh()->rolePermissions->map(
            static fn (RolePermission $rp): string => $rp->permission instanceof Permission
                ? $rp->permission->value
                : (string) $rp->permission,
        )->sort()->values()->all();

        $expected = array_map(
            static fn (Permission $p): string => $p->value,
            array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => ! in_array($p, [
                    Permission::RbacManage,
                    Permission::LegalEntityManage,
                    Permission::CredentialManage,
                ], true),
            )),
        );
        sort($expected);

        $this->assertSame($expected, $restored);
    }

    #[Test]
    public function owner_holds_every_permission(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $owner = Role::query()->where('key', 'owner')->firstOrFail();
        $held = $owner->rolePermissions->map(
            static fn (RolePermission $rp): string => $rp->permission instanceof Permission
                ? $rp->permission->value
                : (string) $rp->permission,
        )->sort()->values()->all();

        $all = array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        sort($all);

        $this->assertSame($all, $held);
    }

    #[Test]
    public function non_owner_roles_are_explicit_lists(): void
    {
        $lists = RbacSystemRoleSeeder::explicitPermissionLists();

        $this->assertNotEmpty($lists);
        $this->assertArrayNotHasKey('owner', $lists);

        foreach ($lists as $key => $permissions) {
            $this->assertNotSame(
                count(Permission::cases()),
                count($permissions),
                "Role {$key} must not silently equal Permission::cases()",
            );
        }

        RbacSystemRoleSeeder::upsertSystemRoles();

        foreach ($lists as $key => $permissions) {
            $role = Role::query()->where('key', $key)->firstOrFail();
            $held = $role->rolePermissions->map(
                static fn (RolePermission $rp): string => $rp->permission instanceof Permission
                    ? $rp->permission->value
                    : (string) $rp->permission,
            )->sort()->values()->all();

            $expected = array_map(static fn (Permission $p): string => $p->value, $permissions);
            sort($expected);

            $this->assertSame($expected, $held, "Role {$key} drifted from explicit list");
        }
    }
}
