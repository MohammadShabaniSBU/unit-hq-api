<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\CredentialStatus;
use App\Models\AircallUserLink;
use App\Models\CommunicationAccount;
use App\Models\Employee;
use App\Models\Site;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AircallMappingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Employee $staff;

    private string $webhookToken = 'tok-aircall-map';

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();
        $this->manager = Employee::factory()->manager()->create(['name' => 'Manager One']);
        $this->staff = Employee::factory()->staff()->create(['name' => 'Staff Two']);
        Sanctum::actingAs($this->manager);

        CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Call,
            'provider' => Provider::Aircall,
            'is_active' => true,
            'credentials' => [
                'api_id' => 'aircall-id',
                'api_token' => 'aircall-token',
            ],
            'webhook_url_token' => $this->webhookToken,
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_sync_map_unlink_unique(): void
    {
        Http::fake([
            'api.aircall.io/v1/users*' => Http::response([
                'users' => [
                    [
                        'id' => 456,
                        'name' => 'Jane Agent',
                        'email' => 'jane@example.com',
                        'availability_status' => 'available',
                    ],
                    [
                        'id' => 789,
                        'name' => 'Bob Agent',
                        'email' => 'bob@example.com',
                        'availability_status' => 'offline',
                    ],
                ],
                'meta' => [
                    'count' => 2,
                    'total' => 2,
                    'current_page' => 1,
                    'per_page' => 50,
                    'next_page_link' => null,
                ],
            ], 200),
        ]);

        $this->postJson('/api/settings/communications/call/aircall/users/sync')
            ->assertOk()
            ->assertJsonPath('data.users.0.id', '456')
            ->assertJsonPath('data.users.0.label', 'Jane Agent')
            ->assertJsonPath('data.users.0.employee_id', null)
            ->assertJsonPath('data.users.1.id', '789');

        $this->putJson('/api/settings/communications/call/aircall/users/456', [
            'employee_id' => $this->manager->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.employee_id', $this->manager->id);

        $this->assertDatabaseHas('aircall_user_links', [
            'employee_id' => $this->manager->id,
            'aircall_user_id' => '456',
            'aircall_user_label' => 'Jane Agent',
        ]);

        // Same employee → another Aircall user must fail (employee unique).
        $this->putJson('/api/settings/communications/call/aircall/users/789', [
            'employee_id' => $this->manager->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);

        // Same Aircall user → another employee must fail (user unique).
        $this->putJson('/api/settings/communications/call/aircall/users/456', [
            'employee_id' => $this->staff->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['aircall_user_id']);

        $this->putJson('/api/settings/communications/call/aircall/users/789', [
            'employee_id' => $this->staff->id,
        ])->assertOk();

        $this->assertSame(2, AircallUserLink::query()->count());

        $this->deleteJson('/api/settings/communications/call/aircall/users/456')
            ->assertOk()
            ->assertJsonPath('data.users.0.employee_id', null);

        $this->assertDatabaseMissing('aircall_user_links', [
            'aircall_user_id' => '456',
        ]);
        $this->assertDatabaseHas('aircall_user_links', [
            'aircall_user_id' => '789',
            'employee_id' => $this->staff->id,
        ]);

        // Cached list still returns both users with updated mappings.
        $list = $this->getJson('/api/settings/communications/call/aircall/users')
            ->assertOk()
            ->json('data.users');

        $this->assertCount(2, $list);
        $byId = collect($list)->keyBy('id');
        $this->assertArrayHasKey('456', $byId->all());
        $this->assertNull($byId['456']['employee_id']);
        $this->assertSame($this->staff->id, $byId['789']['employee_id']);
    }
}
