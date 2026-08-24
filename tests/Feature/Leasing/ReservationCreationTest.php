<?php

declare(strict_types=1);

namespace Tests\Feature\Leasing;

use App\Enums\DealStatus;
use App\Enums\HoldType;
use App\Enums\PipelineSource;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\ReservationCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ReservationCreationTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Price $price;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = $this->authenticateAsEmployee();

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
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $this->contact = Contact::factory()->create();
    }

    #[Test]
    public function auto_pick_skips_occupied_and_held_units(): void
    {
        $occupied = $this->makeUnit('OCC-1');
        $this->occupy($occupied);

        $held = $this->makeUnit('HLD-1');
        UnitHold::query()->create([
            'unit_id' => $held->id,
            'hold_type' => HoldType::Maintenance,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'reason' => 'Repair',
        ]);

        $available = $this->makeUnit('AVL-1');

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ]);

        $response->assertCreated();
        $this->assertSame($available->id, (int) $response->json('data.unit_id'));
    }

    #[Test]
    public function explicit_unit_id_on_occupied_unit_surfaces_guard_422(): void
    {
        $unit = $this->makeUnit('OCC-1');
        $this->occupy($unit);

        $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $unit->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_id']);

        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function deal_site_mismatch_is_rejected(): void
    {
        $unit = $this->makeUnit('AVL-1');
        $otherSite = Site::factory()->create([
            'country_id' => $this->site->country_id,
            'currency' => 'EUR',
        ]);
        $deal = Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $otherSite->id,
            'status' => DealStatus::Qualified,
        ]);

        $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $unit->id,
            'contact_id' => $this->contact->id,
            'deal_id' => $deal->id,
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);

        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function price_id_is_pinned_from_the_current_rate_and_hold_is_written(): void
    {
        $unit = $this->makeUnit('AVL-1');

        $response = $this->postJson('/api/reservations', [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_id' => $unit->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ]);

        $response->assertCreated();

        $reservation = Reservation::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->price->id, $reservation->price_id);

        $hold = UnitHold::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(HoldType::Reservation, $hold->hold_type);
        $this->assertSame($unit->id, $hold->unit_id);
        $this->assertNull($hold->released_at);
        $this->assertSame($this->employee->id, (int) $hold->created_by);
    }

    #[Test]
    public function agent_actor_rejects_second_pending_hold_for_same_contact_site_class(): void
    {
        $this->makeUnit('AVL-1');
        $this->makeUnit('AVL-2');
        $agent = AiAgent::factory()->create();

        $this->createViaAgent($agent);

        try {
            $this->createViaAgent($agent);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('unit_class_id', $e->errors());
        }

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(PipelineSource::AiAgent, Reservation::query()->firstOrFail()->source);
    }

    #[Test]
    public function employee_actor_may_create_two_pending_holds_for_the_same_class(): void
    {
        $this->makeUnit('AVL-1');
        $this->makeUnit('AVL-2');

        $payload = [
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'contact_id' => $this->contact->id,
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ];

        $this->postJson('/api/reservations', $payload)->assertCreated();
        $this->postJson('/api/reservations', $payload)->assertCreated();

        $this->assertSame(2, Reservation::query()->count());
        Reservation::query()->each(function (Reservation $reservation): void {
            $this->assertSame(PipelineSource::Operator, $reservation->source);
            $this->assertNull($reservation->ai_agent_id);
        });
    }

    private function createViaAgent(AiAgent $agent): Reservation
    {
        return ReservationCreation::create(
            $this->site->id,
            $this->unitClass->id,
            $this->contact->id,
            null,
            null,
            now()->addDays(3),
            null,
            null,
            [],
            LeasingActor::agent($agent),
        );
    }

    private function makeUnit(string $number): Unit
    {
        return Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $number,
            'enabled' => true,
        ]);
    }

    private function occupy(Unit $unit): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'currency' => 'EUR',
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => null,
        ]);
    }
}
