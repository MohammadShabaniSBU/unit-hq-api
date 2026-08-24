<?php

declare(strict_types=1);

namespace Tests\Feature\Leasing;

use App\Enums\DealStatus;
use App\Enums\HoldType;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

/**
 * Sequential double-accept is not a lockForUpdate race. SQLite cannot fairly
 * simulate that concurrency; the concurrency guard is verified by inspection
 * plus the partial unique index on offer_id WHERE selected_at IS NOT NULL,
 * not by the sequential test.
 */
class OfferAcceptanceTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private int $unitClassRateId;

    private Contact $contact;

    private Deal $deal;

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
        [$rate] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
        );
        $this->unitClassRateId = $rate->id;
        $this->contact = Contact::factory()->create();
        $this->deal = Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $this->site->id,
            'status' => DealStatus::OfferSent,
            'desired_unit_class_id' => $this->unitClass->id,
        ]);
    }

    #[Test]
    public function happy_path_accepts_offer_and_writes_reservation_hold(): void
    {
        $unit = $this->makeUnit('AVL-1');
        $option = $this->makeOption($unit, 'sent');

        $response = $this->postJson("/api/offer-options/{$option->id}/select");

        $response->assertOk();

        $offer = $option->offer->fresh();
        $this->assertSame('accepted', $offer->status);
        $this->assertNotNull($offer->accepted_at);
        $this->assertNotNull($option->fresh()->selected_at);

        $reservation = Reservation::query()->where('offer_option_id', $option->id)->firstOrFail();
        $this->assertSame($unit->id, $reservation->unit_id);
        $this->assertSame($this->contact->id, $reservation->contact_id);

        $hold = UnitHold::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame(HoldType::Reservation, $hold->hold_type);
        $this->assertNull($hold->released_at);
    }

    #[Test]
    public function expired_offer_is_rejected(): void
    {
        $unit = $this->makeUnit('AVL-1');
        $option = $this->makeOption($unit, 'sent', now()->subDay());

        $this->postJson("/api/offer-options/{$option->id}/select")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer']);

        $this->assertSame('sent', $option->offer->fresh()->status);
        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function already_accepted_offer_is_rejected(): void
    {
        $unit = $this->makeUnit('AVL-1');
        $option = $this->makeOption($unit, 'accepted');
        $option->update(['selected_at' => now()->subHour()]);

        $this->postJson("/api/offer-options/{$option->id}/select")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer']);

        $this->assertSame(0, Reservation::query()->count());
    }

    #[Test]
    public function pinned_unit_gone_resolves_a_fresh_unit(): void
    {
        $pinned = $this->makeUnit('PIN-1');
        $fresh = $this->makeUnit('FSH-1');
        $this->occupy($pinned);

        $option = $this->makeOption($pinned, 'sent');

        $this->postJson("/api/offer-options/{$option->id}/select")->assertOk();

        $reservation = Reservation::query()->where('offer_option_id', $option->id)->firstOrFail();
        $this->assertSame($fresh->id, $reservation->unit_id);
        $this->assertNotSame($pinned->id, $reservation->unit_id);
    }

    #[Test]
    public function no_available_unit_commits_nothing(): void
    {
        $unit = $this->makeUnit('OCC-1');
        $this->occupy($unit);
        $option = $this->makeOption($unit, 'sent');

        $this->postJson("/api/offer-options/{$option->id}/select")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit']);

        $this->assertSame('sent', $option->offer->fresh()->status);
        $this->assertNull($option->fresh()->selected_at);
        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(0, UnitHold::query()->where('hold_type', HoldType::Reservation)->count());
    }

    #[Test]
    public function sequential_double_accept_leaves_one_selected_option(): void
    {
        $unit = $this->makeUnit('AVL-1');
        $option = $this->makeOption($unit, 'sent');

        $this->postJson("/api/offer-options/{$option->id}/select")->assertOk();
        $this->postJson("/api/offer-options/{$option->id}/select")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer']);

        $this->assertSame(1, OfferOption::query()->whereNotNull('selected_at')->count());
        $this->assertSame(1, Reservation::query()->count());
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

    private function makeOption(Unit $unit, string $status, mixed $expiresAt = null): OfferOption
    {
        $offer = Offer::query()->create([
            'deal_id' => $this->deal->id,
            'contact_id' => $this->contact->id,
            'token' => Str::random(64),
            'status' => $status,
            'expires_at' => $expiresAt ?? now()->addDays(7),
        ]);

        return OfferOption::query()->create([
            'offer_id' => $offer->id,
            'unit_class_rate_id' => $this->unitClassRateId,
            'unit_id' => $unit->id,
            'label' => 'Standard',
            'display_order' => 0,
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
