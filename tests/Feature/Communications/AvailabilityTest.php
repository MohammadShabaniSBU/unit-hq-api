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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Site::factory()->create();
        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

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
            'webhook_url_token' => 'tok-aircall-avail',
            'status' => CredentialStatus::Connected,
        ]);
    }

    public function test_drives_button_truth(): void
    {
        // Unmapped → disabled with not_mapped.
        $this->getJson('/api/calls/availability')
            ->assertOk()
            ->assertJsonPath('data.mapped', false)
            ->assertJsonPath('data.can_dial', false)
            ->assertJsonPath('data.disabled_reason', 'not_mapped');

        AircallUserLink::query()->create([
            'employee_id' => $this->employee->id,
            'aircall_user_id' => '456',
            'aircall_user_label' => 'Jane Agent',
        ]);
        Cache::flush();

        $availabilityCalls = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$availabilityCalls) {
            if (! str_contains($request->url(), '/users/456/availability')) {
                return Http::response(['ping' => 'pong'], 200);
            }

            $availabilityCalls++;

            return match ($availabilityCalls) {
                1 => Http::response(['availability' => 'available'], 200),
                2 => Http::response(['availability' => 'offline'], 200),
                default => Http::response(['availability' => 'in_call'], 200),
            };
        });

        $this->getJson('/api/calls/availability')
            ->assertOk()
            ->assertJsonPath('data.mapped', true)
            ->assertJsonPath('data.aircall_user_id', '456')
            ->assertJsonPath('data.aircall_user_label', 'Jane Agent')
            ->assertJsonPath('data.availability', 'available')
            ->assertJsonPath('data.can_dial', true)
            ->assertJsonPath('data.disabled_reason', null);

        // Cache: second HTTP call must not hit Aircall again (still available).
        $this->getJson('/api/calls/availability')
            ->assertOk()
            ->assertJsonPath('data.can_dial', true)
            ->assertJsonPath('data.availability', 'available');
        $this->assertSame(1, $availabilityCalls);

        Cache::flush();

        $this->getJson('/api/calls/availability')
            ->assertOk()
            ->assertJsonPath('data.can_dial', false)
            ->assertJsonPath('data.disabled_reason', 'user_offline')
            ->assertJsonPath('data.availability', 'offline');
        $this->assertSame(2, $availabilityCalls);

        Cache::flush();

        $this->getJson('/api/calls/availability')
            ->assertOk()
            ->assertJsonPath('data.can_dial', false)
            ->assertJsonPath('data.disabled_reason', 'user_busy');
        $this->assertSame(3, $availabilityCalls);
    }
}
