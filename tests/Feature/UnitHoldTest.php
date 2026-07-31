<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Occupancy\HoldGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class UnitHoldTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private UnitClass $unitClass;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $this->price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            [
                'amount' => '196.72',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->unitClass->update(['current_price_id' => $this->price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
    }

    public function test_reservation_creates_hold(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Madrid'));

        $expiresAt = '2026-08-14T23:30:00+02:00';

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => $expiresAt,
        ]);

        $response->assertCreated();
        $reservationId = $response->json('data.id');

        $hold = UnitHold::query()->where('reservation_id', $reservationId)->first();
        $this->assertNotNull($hold);
        $this->assertSame(HoldType::Reservation, $hold->hold_type);
        $this->assertSame($this->unit->id, $hold->unit_id);
        $this->assertSame('2026-08-01', $hold->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-15', $hold->ends_on->format('Y-m-d'));
        $this->assertNull($hold->released_at);
        $this->assertDatabaseCount('unit_holds', 1);

        CarbonImmutable::setTestNow();
    }

    public function test_reservation_hold_ends_on_is_site_local_plus_one_day(): void
    {
        // expires_at = 2026-08-14 23:30 Europe/Madrid → ends_on = 2026-08-15
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Madrid'));

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => '2026-08-14T23:30:00+02:00',
        ]);

        $response->assertCreated();

        $hold = UnitHold::query()
            ->where('reservation_id', $response->json('data.id'))
            ->firstOrFail();

        $this->assertSame('2026-08-15', $hold->ends_on->format('Y-m-d'));

        CarbonImmutable::setTestNow();
    }

    public function test_late_evening_expiry_still_blocks_that_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Madrid'));

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => '2026-08-14T23:30:00+02:00',
        ]);
        $response->assertCreated();

        // Half-open [starts_on, ends_on=2026-08-15): 14 Aug is still covered.
        try {
            HoldGuard::assertUnheld(
                $this->unit->id,
                CarbonImmutable::parse('2026-08-14'),
                CarbonImmutable::parse('2026-08-15'),
            );
            $this->fail('Hold should still block on the site-local expiry day.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->addToAssertionCount(1);
        }

        // First day not covered is ends_on itself.
        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-08-15'),
            null,
        );

        CarbonImmutable::setTestNow();
    }

    public function test_cannot_reserve_occupied_unit(): void
    {
        $contact = Contact::factory()->create();

        $contract = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-03-01',
            'move_in_date' => '2026-03-01',
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '196.72',
                ],
            ],
        ]);
        $contract->assertCreated();
        $this->assertTrue(UnitOccupancy::query()->where('unit_id', $this->unit->id)->exists());

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
        $this->assertDatabaseCount('unit_holds', 0);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_cannot_reserve_held_unit(): void
    {
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'reason' => 'Flooded unit',
        ]);

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['unit_id']);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('unit_holds', 1);
    }

    public function test_conversion_releases_hold_and_opens_occupancy(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Madrid'));

        $create = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => '2026-08-14T23:30:00+02:00',
        ]);
        $create->assertCreated();
        $reservationId = $create->json('data.id');

        $this->assertTrue(
            UnitHold::query()
                ->where('reservation_id', $reservationId)
                ->whereNull('released_at')
                ->exists()
        );

        $convert = $this->postJson("/api/reservations/{$reservationId}/convert", [
            'start_date' => '2026-08-01',
            'move_in_date' => '2026-08-01',
            'unit_rate' => 196.72,
        ]);
        $convert->assertCreated();

        $hold = UnitHold::query()->where('reservation_id', $reservationId)->firstOrFail();
        $this->assertNotNull($hold->released_at);

        $this->assertTrue(
            UnitOccupancy::query()
                ->where('unit_id', $this->unit->id)
                ->where('contract_id', $convert->json('data.id'))
                ->whereNull('ended_on')
                ->exists()
        );

        CarbonImmutable::setTestNow();
    }

    public function test_expired_hold_does_not_block(): void
    {
        // Hold ended (half-open) on 2026-08-01 — no longer covers that day or later.
        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Reservation,
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-08-01',
            'released_at' => null,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 12:00:00', 'Europe/Madrid'));

        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-15'),
        );

        // A new reservation for the same unit should succeed (read-time expiry, no job).
        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => '2026-08-14T23:30:00+02:00',
        ]);
        $response->assertCreated();

        CarbonImmutable::setTestNow();
    }

    public function test_maintenance_hold_blocks_availability(): void
    {
        $response = $this->postJson("/api/units/{$this->unit->id}/holds", [
            'hold_type' => 'maintenance',
            'reason' => 'Roof leak',
        ]);
        $response->assertCreated();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        HoldGuard::assertUnheld(
            $this->unit->id,
            CarbonImmutable::parse($response->json('data.starts_on')),
            null,
        );
    }

    public function test_manual_hold_starts_on_site_today(): void
    {
        // 2026-07-30 22:30 UTC → 2026-07-31 in Madrid.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 22:30:00', 'UTC'));

        $response = $this->postJson("/api/units/{$this->unit->id}/holds", [
            'hold_type' => 'maintenance',
            'reason' => 'Scheduled paint',
        ]);

        $response->assertCreated();
        $this->assertSame('2026-07-31', $response->json('data.starts_on'));
        $this->assertNotSame('2026-07-30', $response->json('data.starts_on'));

        CarbonImmutable::setTestNow();
    }

    public function test_release_sets_timestamp_not_delete(): void
    {
        $create = $this->postJson("/api/units/{$this->unit->id}/holds", [
            'hold_type' => 'maintenance',
            'reason' => 'Door repair',
        ]);
        $create->assertCreated();
        $holdId = $create->json('data.id');

        $before = UnitHold::query()->count();

        $release = $this->deleteJson("/api/units/{$this->unit->id}/holds/{$holdId}");
        $release->assertOk();
        $this->assertNotNull($release->json('data.released_at'));

        $this->assertSame($before, UnitHold::query()->count());
        $this->assertDatabaseHas('unit_holds', [
            'id' => $holdId,
        ]);
        $this->assertNotNull(UnitHold::query()->findOrFail($holdId)->released_at);
    }

    public function test_reservation_hold_not_manageable_via_holds_api(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 10:00:00', 'Europe/Madrid'));

        $create = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'expires_at' => '2026-08-14T23:30:00+02:00',
        ]);
        $create->assertCreated();
        $holdId = UnitHold::query()
            ->where('reservation_id', $create->json('data.id'))
            ->value('id');

        $post = $this->postJson("/api/units/{$this->unit->id}/holds", [
            'hold_type' => 'reservation',
            'reason' => 'should fail',
        ]);
        $post->assertStatus(422)->assertJsonValidationErrors(['hold_type']);

        $delete = $this->deleteJson("/api/units/{$this->unit->id}/holds/{$holdId}");
        $delete->assertStatus(422)->assertJsonValidationErrors(['hold']);

        $this->assertNull(UnitHold::query()->findOrFail($holdId)->released_at);

        CarbonImmutable::setTestNow();
    }
}
