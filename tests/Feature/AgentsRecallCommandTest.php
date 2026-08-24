<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\LogChannel;
use App\Enums\PipelineSource;
use App\Enums\ReservationStatus;
use App\Models\Activity;
use App\Models\AiAgent;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\OfferCreation;
use App\Support\Leasing\ReservationCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class AgentsRecallCommandTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private AiAgent $agent;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Contact $contact;

    private Deal $deal;

    private int $unitClassRateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = AiAgent::factory()->create([
            'key' => 'sales',
            'name' => 'Sales Agent',
        ]);
        $this->employee = Employee::factory()->create();
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
    public function dry_run_defaults_true_and_writes_nothing(): void
    {
        $this->makeUnit('AVL-1');
        $offer = $this->createAgentOffer();
        $reservation = $this->createAgentReservation('AVL-2');

        $this->artisan('agents:recall', [
            '--agent' => 'sales',
            '--since' => '1h',
        ])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain("expire offer #{$offer->id}")
            ->expectsOutputToContain("cancel reservation #{$reservation->id}")
            ->assertSuccessful();

        $this->assertSame('sent', $offer->fresh()->status);
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertNull(UnitHold::query()->where('reservation_id', $reservation->id)->firstOrFail()->released_at);

        $this->assertTrue(
            SystemEvent::query()->where('event', 'agents.recall.started')->exists()
        );
        $this->assertFalse(
            SystemEvent::query()->where('event', 'agents.recall.committed')->exists()
        );
    }

    #[Test]
    public function commit_expires_offers_cancels_reservations_and_releases_holds(): void
    {
        $this->makeUnit('AVL-1');
        $offer = $this->createAgentOffer();
        $reservation = $this->createAgentReservation('AVL-2');

        $this->artisan('agents:recall', [
            '--agent' => 'sales',
            '--since' => '1h',
            '--dry-run' => 'false',
        ])->assertSuccessful();

        $offer = $offer->fresh();
        $this->assertSame('expired', $offer->status);
        $this->assertTrue($offer->expires_at->lte(now()->addSecond()));

        $reservation = $reservation->fresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertNotNull(
            UnitHold::query()->where('reservation_id', $reservation->id)->firstOrFail()->released_at
        );

        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'offer.expired')
                ->where('subject_id', $offer->id)
                ->exists()
        );
        $this->assertTrue(
            Activity::query()
                ->where('log_name', LogChannel::Core->value)
                ->where('description', 'reservation.cancelled')
                ->where('subject_id', $reservation->id)
                ->exists()
        );

        $this->assertTrue(SystemEvent::query()->where('event', 'agents.recall.started')->exists());
        $this->assertTrue(SystemEvent::query()->where('event', 'agents.recall.committed')->exists());
    }

    #[Test]
    public function operator_sourced_rows_are_untouched(): void
    {
        $this->makeUnit('AVL-1');
        $operatorOffer = Offer::query()->create([
            'deal_id' => $this->deal->id,
            'contact_id' => $this->contact->id,
            'token' => 'operator-token-'.str_repeat('x', 48),
            'status' => 'sent',
            'expires_at' => now()->addDays(7),
            'source' => PipelineSource::Operator,
        ]);
        $operatorReservation = $this->createEmployeeReservation('AVL-2');

        $this->artisan('agents:recall', [
            '--agent' => 'sales',
            '--since' => '1h',
            '--dry-run' => 'false',
        ])->assertSuccessful();

        $this->assertSame('sent', $operatorOffer->fresh()->status);
        $this->assertSame(ReservationStatus::Pending, $operatorReservation->fresh()->status);
        $this->assertNull(
            UnitHold::query()->where('reservation_id', $operatorReservation->id)->firstOrFail()->released_at
        );
    }

    #[Test]
    public function accepted_offers_are_reported_and_skipped(): void
    {
        $this->makeUnit('AVL-1');
        $accepted = $this->createAgentOffer();
        $accepted->update(['status' => 'accepted', 'accepted_at' => now()]);

        $this->artisan('agents:recall', [
            '--agent' => 'sales',
            '--since' => '1h',
            '--dry-run' => 'false',
        ])
            ->expectsOutputToContain("SKIP offer #{$accepted->id}")
            ->assertSuccessful();

        $this->assertSame('accepted', $accepted->fresh()->status);

        $committed = SystemEvent::query()->where('event', 'agents.recall.committed')->firstOrFail();
        $this->assertSame([$accepted->id], $committed->payload['skipped_accepted_offer_ids']);
    }

    #[Test]
    public function contracted_reservations_are_reported_and_skipped(): void
    {
        $this->makeUnit('AVL-1');
        $reservation = $this->createAgentReservation('AVL-2');
        $contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'reservation_id' => $reservation->id,
            'currency' => 'EUR',
        ]);

        $this->artisan('agents:recall', [
            '--agent' => 'sales',
            '--since' => '1h',
            '--dry-run' => 'false',
        ])
            ->expectsOutputToContain("SKIP reservation #{$reservation->id}")
            ->assertSuccessful();

        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertNull(
            UnitHold::query()->where('reservation_id', $reservation->id)->firstOrFail()->released_at
        );

        $committed = SystemEvent::query()->where('event', 'agents.recall.committed')->firstOrFail();
        $this->assertSame([$reservation->id], $committed->payload['skipped_contracted_reservation_ids']);
        $this->assertSame($contract->id, $reservation->fresh()->contract->id);
    }

    private function createAgentOffer(): Offer
    {
        return OfferCreation::create(
            [
                'deal_id' => $this->deal->id,
                'contact_id' => $this->contact->id,
                'status' => 'sent',
                'expires_at' => now()->addDays(7),
            ],
            [
                [
                    'unit_class_rate_id' => $this->unitClassRateId,
                    'label' => 'Standard',
                    'display_order' => 0,
                ],
            ],
            [],
            LeasingActor::agent($this->agent),
        );
    }

    private function createAgentReservation(string $unitNumber): Reservation
    {
        $unit = $this->makeUnit($unitNumber);

        return ReservationCreation::create(
            $this->site->id,
            $this->unitClass->id,
            $this->contact->id,
            $this->deal->id,
            $unit->id,
            now()->addDays(3),
            null,
            null,
            [],
            LeasingActor::agent($this->agent),
        );
    }

    private function createEmployeeReservation(string $unitNumber): Reservation
    {
        $unit = $this->makeUnit($unitNumber);

        return ReservationCreation::create(
            $this->site->id,
            $this->unitClass->id,
            $this->contact->id,
            $this->deal->id,
            $unit->id,
            now()->addDays(3),
            null,
            null,
            [],
            LeasingActor::employee($this->employee),
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
}
