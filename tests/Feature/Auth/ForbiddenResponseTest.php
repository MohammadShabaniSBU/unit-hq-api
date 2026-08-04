<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Auth\PermissionDeniedException;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForbiddenResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function shape_is_machine_key_with_permission(): void
    {
        Employee::factory()->manager()->create(); // owner floor

        $employee = Employee::factory()->withoutRoleGrant()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
        $otherSite = Site::factory()->create(['country_id' => $country->id]);
        $unitClass = UnitClass::factory()->create();
        $unit = Unit::factory()->create([
            'site_id' => $otherSite->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $leasingAgent = Role::query()->where('key', 'leasing_agent')->firstOrFail();
        EmployeeRole::query()->create([
            'employee_id' => $employee->id,
            'role_id' => $leasingAgent->id,
            'site_id' => $site->id,
        ]);

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);

        Route::middleware('auth:sanctum')->get('/api/__test/forbidden-shape', function () use ($contract) {
            Gate::authorize('view', $contract->fresh());

            return response()->json(['ok' => true]);
        });

        Sanctum::actingAs($employee);

        $response = $this->getJson('/api/__test/forbidden-shape');

        $response->assertForbidden();
        $response->assertExactJson([
            'message' => 'errors.forbidden',
            'data' => [
                'permission' => 'contract.view',
                'site_id' => $otherSite->id,
            ],
        ]);
    }

    #[Test]
    public function exception_renderer_includes_permission_without_site(): void
    {
        $exception = new PermissionDeniedException(\App\Support\Auth\Permission::SettingsManage);
        $request = Request::create('/api/settings/general', 'GET');
        $request->headers->set('Accept', 'application/json');

        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $exception);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([
            'message' => 'errors.forbidden',
            'data' => [
                'permission' => 'settings.manage',
            ],
        ], $response->getData(true));
    }
}
