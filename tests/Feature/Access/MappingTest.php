<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\AccessPointType;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\FakeAccessProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MappingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private AccessProviderAccount $account;

    private Site $site;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        FakeAccessProvider::reset();
        $registry = app(AccessProviderRegistry::class);
        $registry->register('sensorberg', FakeAccessProvider::class);
        $registry->set(FakeAccessProvider::make(['api_key' => 'fake_ok']));

        $this->employee = Employee::factory()->manager()->create();
        $this->actingAs($this->employee);

        $this->account = AccessProviderAccount::factory()->create([
            'provider' => 'sensorberg',
            'is_active' => true,
            'discovered_points' => [
                [
                    'provider_point_id' => 'fake-gate-1',
                    'label' => 'Main gate',
                    'kind_hint' => 'gate',
                ],
                [
                    'provider_point_id' => 'fake-door-al6-06',
                    'label' => 'Unit AL6-06 door',
                    'kind_hint' => 'unit_door',
                ],
                [
                    'provider_point_id' => 'extra-door',
                    'label' => 'Unit B-12 door',
                    'kind_hint' => 'unit_door',
                ],
            ],
            'points_discovered_at' => now(),
        ]);

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'AL6-06',
        ]);
        Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'B-12',
        ]);
    }

    protected function tearDown(): void
    {
        FakeAccessProvider::reset();
        app(AccessProviderRegistry::class)->set(null);
        parent::tearDown();
    }

    public function test_assign_bulk_archive(): void
    {
        $list = $this->getJson('/api/settings/access/points');
        $list->assertOk();
        $rows = collect($list->json('data.rows'));
        $this->assertCount(3, $rows);
        $this->assertSame(3, $rows->where('status', 'unassigned')->count());

        $assignGate = $this->postJson('/api/settings/access/points', [
            'provider_point_id' => 'fake-gate-1',
            'site_id' => $this->site->id,
            'point_type' => AccessPointType::Gate->value,
        ]);
        $assignGate->assertCreated()
            ->assertJsonPath('data.point_type', 'gate')
            ->assertJsonPath('data.site_id', $this->site->id);

        $suggest = $this->postJson('/api/settings/access/points/suggest');
        $suggest->assertOk();
        $suggestions = collect($suggest->json('data.suggestions'));
        $this->assertGreaterThanOrEqual(2, $suggestions->count());
        $al6 = $suggestions->firstWhere('provider_point_id', 'fake-door-al6-06');
        $this->assertNotNull($al6);
        $this->assertSame($this->unit->id, $al6['suggested_unit_id']);
        $this->assertSame('exact', $al6['confidence']);

        $bulk = $this->postJson('/api/settings/access/points/bulk-assign', [
            'assignments' => $suggestions->map(fn (array $s): array => [
                'provider_point_id' => $s['provider_point_id'],
                'site_id' => $s['suggested_site_id'],
                'unit_id' => $s['suggested_unit_id'],
                'point_type' => $s['suggested_point_type'],
            ])->values()->all(),
        ]);
        $bulk->assertCreated();
        $confirmed = (int) $bulk->json('data.confirmed_count');
        $this->assertGreaterThanOrEqual(2, $confirmed);
        $this->assertSame($confirmed, count($bulk->json('data.points')));

        $door = AccessPoint::query()
            ->active()
            ->where('provider_point_id', 'fake-door-al6-06')
            ->firstOrFail();
        $this->assertSame($this->unit->id, $door->unit_id);

        $archive = $this->postJson("/api/settings/access/points/{$door->id}/archive");
        $archive->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $replacement = $this->postJson('/api/settings/access/points', [
            'provider_point_id' => 'fake-door-al6-06',
            'site_id' => $this->site->id,
            'unit_id' => $this->unit->id,
            'point_type' => AccessPointType::UnitDoor->value,
        ]);
        // Already archived mapping released the unit; but provider_point may still
        // be "unassigned" in discovery. Recreate after archive is allowed.
        // Wait — we archived the only mapping of fake-door-al6-06, so it should
        // appear unassigned again and re-assign should succeed.
        $replacement->assertCreated();

        // Vanished: drop a discovered point that remains assigned.
        $gate = AccessPoint::query()
            ->active()
            ->where('provider_point_id', 'fake-gate-1')
            ->firstOrFail();

        $this->account->forceFill([
            'discovered_points' => [
                [
                    'provider_point_id' => 'fake-door-al6-06',
                    'label' => 'Unit AL6-06 door',
                    'kind_hint' => 'unit_door',
                ],
            ],
        ])->save();

        $after = $this->getJson('/api/settings/access/points');
        $after->assertOk();
        $vanished = collect($after->json('data.rows'))->firstWhere('status', 'vanished');
        $this->assertNotNull($vanished);
        $this->assertSame($gate->id, $vanished['id']);
        $this->assertSame('fake-gate-1', $vanished['provider_point_id']);
    }
}
