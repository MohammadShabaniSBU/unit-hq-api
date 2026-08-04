<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeInvitation;
use Database\Seeders\RbacSystemRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RbacSystemRoleSeeder::upsertSystemRoles();
        $this->owner = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->owner);
    }

    /**
     * @return array{employee: Employee, token: string, invitation: EmployeeInvitation}
     */
    private function createInvitedEmployee(): array
    {
        $response = $this->postJson('/api/employees', [
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'email' => 'pat@example.com',
            'grants' => [],
        ])->assertCreated();

        $link = (string) $response->json('data.invite_link');
        $token = (string) substr($link, (int) strrpos($link, '/') + 1);
        $employee = Employee::query()->where('email', 'pat@example.com')->firstOrFail();
        $invitation = EmployeeInvitation::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();

        return compact('employee', 'token', 'invitation');
    }

    #[Test]
    public function accept_sets_password_and_signs_in(): void
    {
        ['token' => $token, 'employee' => $employee] = $this->createInvitedEmployee();

        $response = $this->postJson("/api/invitations/{$token}/accept", [
            'password' => 'long-enough-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'employee' => ['id', 'email']]]);

        $employee->refresh();
        $this->assertNotNull($employee->password);
        $this->assertNotNull($employee->last_login_at);
        $this->assertSame('active', $employee->status());
    }

    #[Test]
    public function token_is_single_use(): void
    {
        ['token' => $token] = $this->createInvitedEmployee();

        $this->postJson("/api/invitations/{$token}/accept", [
            'password' => 'long-enough-password',
        ])->assertOk();

        $this->postJson("/api/invitations/{$token}/accept", [
            'password' => 'another-long-password',
        ])->assertStatus(410);
    }

    #[Test]
    public function expired_token_rejected_without_sweeper(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->createInvitedEmployee();

        $invitation->expires_at = now()->subMinute();
        $invitation->save();

        $this->getJson("/api/invitations/{$token}")->assertStatus(410);
        $this->postJson("/api/invitations/{$token}/accept", [
            'password' => 'long-enough-password',
        ])->assertStatus(410);
    }

    #[Test]
    public function resend_revokes_previous_token(): void
    {
        ['token' => $oldToken, 'employee' => $employee] = $this->createInvitedEmployee();

        $resend = $this->postJson("/api/employees/{$employee->id}/invitations", [])
            ->assertCreated();

        $newLink = (string) $resend->json('data.invite_link');
        $newToken = (string) substr($newLink, (int) strrpos($newLink, '/') + 1);

        $this->assertNotSame($oldToken, $newToken);

        $this->getJson("/api/invitations/{$oldToken}")->assertStatus(410);
        $this->getJson("/api/invitations/{$newToken}")
            ->assertOk()
            ->assertJsonPath('data.email', 'pat@example.com');
    }

    #[Test]
    public function token_stored_hashed(): void
    {
        ['token' => $token, 'invitation' => $invitation] = $this->createInvitedEmployee();

        $this->assertDatabaseMissing('employee_invitations', ['token_hash' => $token]);
        $this->assertSame(EmployeeInvitation::hashToken($token), $invitation->token_hash);
        $this->assertDatabaseHas('employee_invitations', [
            'id' => $invitation->id,
            'token_hash' => EmployeeInvitation::hashToken($token),
        ]);
    }

    #[Test]
    public function accept_is_throttled(): void
    {
        ['token' => $token] = $this->createInvitedEmployee();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/invitations/{$token}/accept", [
                'password' => 'short',
            ]);
        }

        $this->postJson("/api/invitations/{$token}/accept", [
            'password' => 'short',
        ])->assertStatus(429);
    }
}
