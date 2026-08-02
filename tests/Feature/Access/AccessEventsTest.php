<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessEventType;
use App\Enums\AccessSuspensionReason;
use App\Models\AccessEvent;
use App\Models\AccessGrant;
use App\Models\AccessSuspension;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Access\AccessSyncTestSetup;
use Tests\TestCase;

class AccessEventsTest extends TestCase
{
    use AccessSyncTestSetup;
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccessSyncFixture();
        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);
    }

    protected function tearDown(): void
    {
        $this->tearDownAccessSyncFixture();
        parent::tearDown();
    }

    public function test_filters_cursor_bounded(): void
    {
        AccessSuspension::query()->create([
            'contract_id' => $this->contract->id,
            'reason' => AccessSuspensionReason::Manual,
            'created_by' => $this->employee->id,
            'created_at' => Carbon::parse('2026-08-01 10:00:00', 'Europe/Madrid'),
        ]);

        $grant = AccessGrant::factory()->create([
            'access_point_id' => $this->door->id,
            'contact_id' => $this->contact->id,
            'contract_id' => $this->contract->id,
            'provider_grant_id' => 'grant-door-1',
        ]);

        $base = Carbon::parse('2026-08-10 12:00:00', 'Europe/Madrid');
        for ($i = 0; $i < 5; $i++) {
            AccessEvent::query()->create([
                'access_point_id' => $this->door->id,
                'contact_id' => $this->contact->id,
                'access_grant_id' => $grant->id,
                'event_type' => $i % 2 === 0 ? AccessEventType::Denied : AccessEventType::Granted,
                'occurred_at' => $base->copy()->addMinutes($i),
                'provider_credential_ref' => 'cred-1',
                'provider_point_id' => $this->door->provider_point_id,
                'raw' => ['i' => $i],
                'created_at' => now(),
            ]);
        }

        AccessEvent::query()->create([
            'access_point_id' => $this->gate->id,
            'contact_id' => null,
            'access_grant_id' => null,
            'event_type' => AccessEventType::Denied,
            'occurred_at' => $base->copy()->addHour(),
            'provider_credential_ref' => 'unresolved-cred',
            'provider_point_id' => $this->gate->provider_point_id,
            'raw' => [],
            'created_at' => now(),
        ]);

        $all = $this->getJson('/api/access/events?per_page=2');
        $all->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertNotNull($all->json('meta.next_cursor'));

        $page2 = $this->getJson('/api/access/events?per_page=2&cursor='.urlencode((string) $all->json('meta.next_cursor')));
        $page2->assertOk();
        $this->assertCount(2, $page2->json('data'));
        $ids = array_merge(
            collect($all->json('data'))->pluck('id')->all(),
            collect($page2->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(4, array_unique($ids));

        $denied = $this->getJson('/api/access/events?denied_only=1&per_page=50');
        $denied->assertOk();
        $this->assertTrue(collect($denied->json('data'))->every(
            fn (array $row): bool => $row['event_type'] === 'denied',
        ));

        $contactEvents = $this->getJson("/api/contacts/{$this->contact->id}/access-events");
        $contactEvents->assertOk();
        $this->assertTrue(collect($contactEvents->json('data'))->every(
            fn (array $row): bool => $row['contact_id'] === $this->contact->id,
        ));

        $unitEvents = $this->getJson("/api/units/{$this->unit->id}/access-events");
        $unitEvents->assertOk();
        $this->assertTrue(collect($unitEvents->json('data'))->every(
            fn (array $row): bool => $row['unit_id'] === $this->unit->id,
        ));

        $restricted = collect($denied->json('data'))
            ->first(fn (array $row): bool => $row['access_point_id'] === $this->door->id);
        $this->assertNotNull($restricted);
        $this->assertSame('suspended', $restricted['restriction_context']);

        $tooBig = $this->getJson('/api/access/events?per_page=101');
        $tooBig->assertStatus(422);
    }
}
