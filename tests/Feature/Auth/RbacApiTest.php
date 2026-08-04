<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Support\Auth\Permission;
use App\Support\Auth\RoleScope;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function user_endpoint_returns_roles_and_permissions_without_deprecated_role(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'email',
                'roles' => [['key', 'label', 'site_id']],
                'permissions',
                'company_permissions',
            ],
        ]);
        $this->assertArrayNotHasKey('role', $response->json('data'));
        $this->assertContains('rbac.manage', $response->json('data.company_permissions'));
    }

    #[Test]
    public function permissions_endpoint_groups_by_domain(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/permissions');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('contact', $data);
        $this->assertArrayHasKey('rbac', $data);
        $this->assertNotEmpty($data['contact']);
        $this->assertSame('contact.view', $data['contact'][0]['permission']);
        $this->assertSame('permissions.contact.view', $data['contact'][0]['i18n_key']);
    }

    #[Test]
    public function roles_endpoint_lists_system_roles(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/roles');

        $response->assertOk();
        $keys = collect($response->json('data'))->pluck('key')->all();
        $this->assertContains('owner', $keys);
        $this->assertContains('leasing_agent', $keys);

        $owner = collect($response->json('data'))->firstWhere('key', 'owner');
        $this->assertNotEmpty($owner['permissions']);
        $this->assertContains('rbac.manage', $owner['permissions']);
    }

    #[Test]
    public function roles_status_filter_active_archived_all(): void
    {
        $actor = Employee::factory()->manager()->create();
        Sanctum::actingAs($actor);

        $custom = Role::query()->create([
            'key' => 'custom_ops',
            'label' => 'Custom Ops',
            'scope_level' => RoleScope::Any,
            'is_system' => false,
            'archived_at' => now(),
        ]);

        $active = $this->getJson('/api/roles');
        $active->assertOk();
        $this->assertNotContains('custom_ops', collect($active->json('data'))->pluck('key')->all());

        $archived = $this->getJson('/api/roles?status=archived');
        $archived->assertOk();
        $this->assertContains('custom_ops', collect($archived->json('data'))->pluck('key')->all());

        $all = $this->getJson('/api/roles?status=all');
        $all->assertOk();
        $keys = collect($all->json('data'))->pluck('key')->all();
        $this->assertContains('owner', $keys);
        $this->assertContains('custom_ops', $keys);
        $this->assertNotNull($custom->id);
    }

    #[Test]
    public function can_create_custom_role_and_update_permissions(): void
    {
        $actor = Employee::factory()->manager()->create();
        Sanctum::actingAs($actor);

        $create = $this->postJson('/api/roles', [
            'key' => 'floor_lead',
            'label' => 'Floor Lead',
            'description' => 'Cloned from leasing',
            'scope_level' => RoleScope::Site->value,
            'permissions' => [
                Permission::ContactView->value,
                Permission::ContactManage->value,
                Permission::UnitView->value,
            ],
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.key', 'floor_lead');
        $create->assertJsonPath('data.is_system', false);
        $this->assertSame(3, $create->json('data.permission_count'));

        $roleId = $create->json('data.id');

        $update = $this->patchJson("/api/roles/{$roleId}", [
            'label' => 'Floor Lead Plus',
            'permissions' => [
                Permission::ContactView->value,
                Permission::UnitView->value,
            ],
        ]);

        $update->assertOk();
        $update->assertJsonPath('data.label', 'Floor Lead Plus');
        $this->assertSame(
            [Permission::ContactView->value, Permission::UnitView->value],
            $update->json('data.permissions'),
        );
    }

    #[Test]
    public function system_role_rejects_patch_and_archive(): void
    {
        $actor = Employee::factory()->manager()->create();
        Sanctum::actingAs($actor);

        $ownerId = (int) Role::query()->where('key', 'owner')->value('id');

        $patch = $this->patchJson("/api/roles/{$ownerId}", [
            'label' => 'Hacked',
            'permissions' => [Permission::ContactView->value],
        ]);
        $patch->assertStatus(422);
        $patch->assertJsonValidationErrors(['role']);

        $archive = $this->postJson("/api/roles/{$ownerId}/archive");
        $archive->assertStatus(422);
        $archive->assertJsonValidationErrors(['role']);

        $this->assertSame('owner', Role::query()->findOrFail($ownerId)->key);
        $this->assertNull(Role::query()->findOrFail($ownerId)->archived_at);
    }

    #[Test]
    public function can_archive_and_unarchive_custom_role(): void
    {
        $actor = Employee::factory()->manager()->create();
        Sanctum::actingAs($actor);

        $roleId = $this->postJson('/api/roles', [
            'key' => 'temp_role',
            'label' => 'Temp',
            'scope_level' => RoleScope::Any->value,
            'permissions' => [Permission::ContactView->value],
        ])->json('data.id');

        $this->postJson("/api/roles/{$roleId}/archive")->assertOk();
        $this->assertNotNull(Role::query()->findOrFail($roleId)->archived_at);

        $this->postJson("/api/roles/{$roleId}/unarchive")->assertOk();
        $this->assertNull(Role::query()->findOrFail($roleId)->archived_at);
    }

    #[Test]
    public function employees_list_includes_grant_summary(): void
    {
        $owner = Employee::factory()->manager()->create();
        $site = Site::factory()->create(['name' => 'Camden']);
        $agent = Employee::factory()->withoutRoleGrant()->create(['name' => 'Ana Agent']);
        $agentRoleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');
        EmployeeRole::query()->create([
            'employee_id' => $agent->id,
            'role_id' => $agentRoleId,
            'site_id' => $site->id,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/employees');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $agent->id);
        $this->assertNotNull($row);
        $this->assertSame('Ana Agent', $row['name']);
        $this->assertCount(1, $row['grants']);
        $this->assertSame('leasing_agent', $row['grants'][0]['role_key']);
        $this->assertSame($site->id, $row['grants'][0]['site_id']);
        $this->assertSame('Camden', $row['grants'][0]['site_name']);
        $this->assertFalse($row['grants'][0]['is_company_wide']);
    }

    #[Test]
    public function can_grant_and_revoke_role(): void
    {
        $owner = Employee::factory()->manager()->create();
        $target = Employee::factory()->withoutRoleGrant()->create();
        $site = Site::factory()->create();
        $roleId = (int) Role::query()->where('key', 'site_manager')->value('id');

        Sanctum::actingAs($owner);

        $grant = $this->postJson("/api/employees/{$target->id}/roles", [
            'role_id' => $roleId,
            'site_id' => $site->id,
        ]);
        $grant->assertCreated();
        $grantId = $grant->json('data.id');

        $list = $this->getJson("/api/employees/{$target->id}/roles");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        $this->deleteJson("/api/employees/{$target->id}/roles/{$grantId}")->assertOk();
        $this->assertDatabaseMissing('employee_roles', ['id' => $grantId]);
    }

    #[Test]
    public function grant_rejects_scope_mismatch(): void
    {
        $owner = Employee::factory()->manager()->create();
        $target = Employee::factory()->withoutRoleGrant()->create();
        $site = Site::factory()->create();
        $ownerRoleId = (int) Role::query()->where('key', 'owner')->value('id');
        $agentRoleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');

        Sanctum::actingAs($owner);

        $companyWithSite = $this->postJson("/api/employees/{$target->id}/roles", [
            'role_id' => $ownerRoleId,
            'site_id' => $site->id,
        ]);
        $companyWithSite->assertStatus(422);
        $companyWithSite->assertJsonValidationErrors(['site_id']);

        $siteWithoutSite = $this->postJson("/api/employees/{$target->id}/roles", [
            'role_id' => $agentRoleId,
        ]);
        $siteWithoutSite->assertStatus(422);
        $siteWithoutSite->assertJsonValidationErrors(['site_id']);
    }

    #[Test]
    public function last_owner_grant_cannot_be_removed_via_api(): void
    {
        $owner = Employee::factory()->manager()->create();
        $grant = EmployeeRole::query()
            ->where('employee_id', $owner->id)
            ->where('role_id', Role::query()->where('key', 'owner')->value('id'))
            ->whereNull('site_id')
            ->firstOrFail();

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/employees/{$owner->id}/roles/{$grant->id}");
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
        $this->assertDatabaseHas('employee_roles', ['id' => $grant->id]);
    }

    #[Test]
    public function rbac_endpoints_forbid_unpermitted_employee(): void
    {
        Employee::factory()->manager()->create(); // owner floor
        $agent = Employee::factory()->withoutRoleGrant()->create();
        $roleId = (int) Role::query()->where('key', 'leasing_agent')->value('id');
        EmployeeRole::query()->create([
            'employee_id' => $agent->id,
            'role_id' => $roleId,
            'site_id' => Site::factory()->create()->id,
        ]);

        Sanctum::actingAs($agent);

        $this->getJson('/api/employees')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/roles', [
            'key' => 'nope',
            'label' => 'Nope',
            'scope_level' => RoleScope::Any->value,
            'permissions' => [Permission::ContactView->value],
        ])->assertForbidden();
    }
}
