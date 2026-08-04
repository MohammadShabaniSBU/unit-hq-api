<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeactivationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $owner;

    protected function setUp(): void
    {
        parent::setUp();
        RbacSystemRoleSeeder::upsertSystemRoles();
        $this->owner = Employee::factory()->manager()->create([
            'email' => 'owner@example.com',
            'password' => 'password-password',
        ]);
        Sanctum::actingAs($this->owner);
    }

    #[Test]
    public function revokes_tokens_in_same_transaction(): void
    {
        $staff = Employee::factory()->staff()->create([
            'email' => 'staff@example.com',
            'password' => 'password-password',
        ]);
        $staff->createToken('panel');
        $this->assertSame(1, $staff->tokens()->count());

        $this->postJson("/api/employees/{$staff->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'deactivated');

        $this->assertSame(0, $staff->tokens()->count());
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => Employee::class,
            'tokenable_id' => $staff->id,
        ]);
    }

    #[Test]
    public function deactivated_login_returns_generic_error(): void
    {
        $staff = Employee::factory()->staff()->create([
            'email' => 'deactivated@example.com',
            'password' => 'password-password',
        ]);

        $this->postJson("/api/employees/{$staff->id}/deactivate")->assertOk();

        $wrong = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $deactivated = $this->postJson('/api/login', [
            'email' => 'deactivated@example.com',
            'password' => 'password-password',
        ]);

        $wrong->assertStatus(422);
        $deactivated->assertStatus(422);
        $this->assertSame($wrong->json('errors.email'), $deactivated->json('errors.email'));
    }

    #[Test]
    public function cannot_deactivate_last_owner(): void
    {
        $this->postJson("/api/employees/{$this->owner->id}/deactivate")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee']);

        $this->owner->refresh();
        $this->assertNull($this->owner->deactivated_at);
    }

    #[Test]
    public function reactivation_restores_grants(): void
    {
        $staff = Employee::factory()->staff()->create();
        $grantCount = $staff->employeeRoles()->count();
        $this->assertGreaterThan(0, $grantCount);

        $this->postJson("/api/employees/{$staff->id}/deactivate")->assertOk();
        $this->assertSame($grantCount, $staff->employeeRoles()->count());

        $this->postJson("/api/employees/{$staff->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame($grantCount, $staff->employeeRoles()->count());
        $this->assertNull($staff->fresh()->deactivated_at);
    }
}
