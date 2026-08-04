<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeRole;
use App\Models\Role;
use App\Support\Auth\OwnerFloor;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OwnerFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
    }

    #[Test]
    public function cannot_revoke_last_owner(): void
    {
        $owner = Employee::factory()->manager()->create();
        $grant = EmployeeRole::query()
            ->where('employee_id', $owner->id)
            ->where('role_id', Role::query()->where('key', 'owner')->value('id'))
            ->whereNull('site_id')
            ->firstOrFail();

        try {
            OwnerFloor::revoke($grant);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                [__('errors.rbac.last_owner')],
                $e->errors()['role'] ?? null,
            );

            $request = Request::create('/api/employee-roles/'.$grant->id, 'DELETE');
            $request->headers->set('Accept', 'application/json');
            $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $e);
            $this->assertSame(422, $response->getStatusCode());
        }

        $this->assertDatabaseHas('employee_roles', ['id' => $grant->id]);
    }

    #[Test]
    public function can_revoke_owner_when_another_remains(): void
    {
        $a = Employee::factory()->manager()->create();
        $b = Employee::factory()->manager()->create();

        $ownerRoleId = (int) Role::query()->where('key', 'owner')->value('id');
        $grantA = EmployeeRole::query()
            ->where('employee_id', $a->id)
            ->where('role_id', $ownerRoleId)
            ->whereNull('site_id')
            ->firstOrFail();

        OwnerFloor::revoke($grantA);

        $this->assertDatabaseMissing('employee_roles', ['id' => $grantA->id]);
        $this->assertSame(
            1,
            EmployeeRole::query()->where('role_id', $ownerRoleId)->whereNull('site_id')->count(),
        );
        $this->assertTrue(
            EmployeeRole::query()
                ->where('employee_id', $b->id)
                ->where('role_id', $ownerRoleId)
                ->whereNull('site_id')
                ->exists(),
        );
    }
}
