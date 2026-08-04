<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Employee;
use App\Support\Auth\Actor;
use App\Support\Auth\Permission;
use App\Support\Auth\SystemActor;
use App\Support\Automation\AutomationContext;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemActorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function gate_before_allows_system_actor(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        $this->assertTrue(
            Gate::forUser(new SystemActor)->allows('vacate', $contract),
        );
    }

    #[Test]
    public function gate_before_returns_null_for_employees(): void
    {
        RbacSystemRoleSeeder::upsertSystemRoles();

        $employee = Employee::factory()->withoutRoleGrant()->create();
        $this->assertFalse(
            $employee->allowsPermission(Permission::ContractVacate),
        );

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        // Policies still run — employee with no grants is denied (not short-circuited to allow).
        $this->assertTrue(
            Gate::forUser($employee)->denies('vacate', $contract),
        );

        // And an owner is allowed via the policy path, not Gate::before.
        $owner = Employee::factory()->manager()->create();
        $this->assertTrue(
            Gate::forUser($owner)->allows('vacate', $contract),
        );
    }

    #[Test]
    public function automation_write_does_not_require_permission(): void
    {
        $employee = Employee::factory()->withoutRoleGrant()->create();
        $this->actingAs($employee);

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        AutomationContext::clear();

        try {
            AutomationContext::run(1, function () use ($contract): void {
                $actor = Actor::current();
                $this->assertInstanceOf(SystemActor::class, $actor);
                $this->assertTrue(
                    Gate::forUser($actor)->allows('vacate', $contract),
                );
            });
        } finally {
            AutomationContext::clear();
        }
    }

    #[Test]
    public function headless_actor_is_system_without_authenticated_employee(): void
    {
        $this->assertInstanceOf(SystemActor::class, Actor::current());

        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
        ]);

        Gate::forUser(Actor::current())->authorize('vacate', $contract);

        $this->addToAssertionCount(1);
    }
}
