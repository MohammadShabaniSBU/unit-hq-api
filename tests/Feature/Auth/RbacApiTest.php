<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
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
    public function user_endpoint_returns_roles_and_permissions(): void
    {
        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('data.role', 'owner');
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'email',
                'role',
                'roles' => [['key', 'label', 'site_id']],
                'permissions',
                'company_permissions',
            ],
        ]);
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
}
