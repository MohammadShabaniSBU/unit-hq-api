<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Support\Auth\Exempt;
use App\Support\Auth\Permission;
use App\Support\Auth\RoutePermissions;
use Database\Factories\EmployeeFactory;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PolicyRolloutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function owner_reaches_every_endpoint(): void
    {
        $manifest = RoutePermissions::all();
        $routeKeys = $this->authenticatedRouteKeys();

        foreach ($manifest as $key => $decision) {
            $this->assertContains(
                $key,
                $routeKeys,
                "RoutePermissions key [{$key}] is not a registered authenticated api route",
            );
            $this->assertTrue(
                $decision instanceof Permission || $decision instanceof Exempt,
                "RoutePermissions [{$key}] must be Permission or Exempt",
            );
        }

        $owner = Employee::factory()->manager()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                Gate::forUser($owner)->allows($permission->value),
                "Owner must Gate-allow {$permission->value}",
            );
        }

        Sanctum::actingAs($owner);

        foreach ([
            '/api/units',
            '/api/contacts',
            '/api/contracts',
            '/api/invoices',
            '/api/user',
            '/api/countries/options',
        ] as $uri) {
            $response = $this->getJson($uri);
            $this->assertNotSame(
                403,
                $response->status(),
                "Owner GET {$uri} must not be forbidden (got {$response->status()})",
            );
        }
    }

    #[Test]
    #[DataProvider('unpermittedWriteProvider')]
    public function unpermitted_role_is_denied(string $method, string $uriTemplate, string $permission): void
    {
        Employee::factory()->manager()->create(); // owner floor

        $replacements = [
            '{legal_entity}' => (string) LegalEntity::factory()->create()->id,
            '{site}' => (string) Site::factory()->create()->id,
        ];
        $uri = str_replace(array_keys($replacements), array_values($replacements), $uriTemplate);

        $employee = Employee::factory()->withoutRoleGrant()->create();
        EmployeeFactory::grantCompanyRole($employee, 'read_only');
        Sanctum::actingAs($employee);

        $response = $this->json($method, $uri, []);

        $response->assertForbidden();
        $response->assertJson([
            'message' => 'errors.forbidden',
            'data' => [
                'permission' => $permission,
            ],
        ]);
    }

    /**
     * One denial per domain (read_only has *.view only).
     * Prefer endpoints that authorize before validation / model work.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unpermittedWriteProvider(): array
    {
        return [
            'contact' => ['POST', '/api/contacts', Permission::ContactManage->value],
            'contract' => ['POST', '/api/contracts', Permission::ContractSign->value],
            'unit' => ['POST', '/api/units', Permission::UnitManage->value],
            'site' => ['POST', '/api/sites/{site}/archive', Permission::SiteManage->value],
            'tax' => ['POST', '/api/tax-rates', Permission::TaxRateManage->value],
            'billing' => ['POST', '/api/billing-runs', Permission::BillingRunExecute->value],
            'settings' => ['PATCH', '/api/settings/billing', Permission::BillingSettingsManage->value],
            'rbac' => ['GET', '/api/roles', Permission::RbacManage->value],
            'credential' => ['GET', '/api/legal-entities/{legal_entity}/stripe-settings', Permission::CredentialManage->value],
        ];
    }

    /**
     * @return list<string>
     */
    private function authenticatedRouteKeys(): array
    {
        $keys = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            if (! $this->hasSanctum($route->gatherMiddleware())) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $keys[] = strtoupper($method).' /'.$uri;
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $middleware
     */
    private function hasSanctum(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if ($name === 'auth:sanctum' || str_contains($name, 'Authenticate:sanctum')) {
                return true;
            }
        }

        return false;
    }
}
