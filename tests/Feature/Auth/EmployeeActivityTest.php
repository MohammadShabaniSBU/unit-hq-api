<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class EmployeeActivityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $owner;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        $this->owner = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->owner);
    }

    #[Test]
    public function lifecycle_events_contain_no_secrets(): void
    {
        $create = $this->postJson('/api/employees', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'grants' => [],
        ])->assertCreated();

        $link = (string) $create->json('data.invite_link');
        $token = (string) substr($link, (int) strrpos($link, '/') + 1);
        $employeeId = (int) $create->json('data.id');

        $this->postJson("/api/invitations/{$token}/accept", [
            'password' => 'long-enough-password',
        ])->assertOk();

        Sanctum::actingAs($this->owner);

        $this->postJson("/api/employees/{$employeeId}/deactivate")->assertOk();
        $this->postJson("/api/employees/{$employeeId}/reactivate")->assertOk();

        $events = Activity::query()
            ->whereIn('event', [
                'employee.created',
                'employee.invited',
                'employee.invitation.accepted',
                'employee.deactivated',
                'employee.reactivated',
            ])
            ->get();

        $this->assertGreaterThanOrEqual(5, $events->count());

        foreach ($events as $activity) {
            $json = json_encode($activity->properties?->toArray() ?? []);
            $this->assertIsString($json);
            $this->assertStringNotContainsString($token, $json);
            $this->assertStringNotContainsString('long-enough-password', $json);
            $props = $activity->properties?->toArray() ?? [];
            $this->assertArrayNotHasKey('password', $props);
            $this->assertArrayNotHasKey('token', $props);
            $this->assertArrayNotHasKey('token_hash', $props);
        }
    }
}
