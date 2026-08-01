<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Contracts\ActivatePendingContracts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ActivationTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Country $country;

    private LegalEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        // Instant where Madrid is already 2026-08-15 and Honolulu is still 2026-08-14.
        Carbon::setTestNow(Carbon::parse('2026-08-14 22:30:00', 'UTC'));

        $this->employee = Employee::factory()->manager()->create();
        $this->country = Country::factory()->create(['code' => 'ES']);
        $this->entity = LegalEntity::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flips_on_site_local_date(): void
    {
        $madrid = $this->makeSite('Europe/Madrid');
        $honolulu = $this->makeSite('Pacific/Honolulu');
        [$madridUnit, $madridPriceId] = $this->makeUnit($madrid);
        [$honoluluUnit, $honoluluPriceId] = $this->makeUnit($honolulu);

        // Madrid today = 2026-08-15; Honolulu today = 2026-08-14.
        $dueMadrid = $this->makePendingContract($madridUnit, $madridPriceId, '2026-08-15');
        $tomorrowMadrid = $this->makePendingContract($madridUnit, $madridPriceId, '2026-08-16');
        $dueHonolulu = $this->makePendingContract($honoluluUnit, $honoluluPriceId, '2026-08-14');
        $notYetHonolulu = $this->makePendingContract($honoluluUnit, $honoluluPriceId, '2026-08-15');
        $cancelled = $this->makePendingContract($madridUnit, $madridPriceId, '2026-08-15');
        $cancelled->forceFill(['status' => ContractStatus::Cancelled])->save();

        $exit = Artisan::call('contracts:activate');
        $this->assertSame(0, $exit);

        $dueMadrid->refresh();
        $tomorrowMadrid->refresh();
        $dueHonolulu->refresh();
        $notYetHonolulu->refresh();
        $cancelled->refresh();

        $this->assertSame(ContractStatus::Active, $dueMadrid->status);
        $this->assertSame(ContractStatus::Pending, $tomorrowMadrid->status);
        $this->assertSame(ContractStatus::Active, $dueHonolulu->status);
        $this->assertSame(ContractStatus::Pending, $notYetHonolulu->status);
        $this->assertSame(ContractStatus::Cancelled, $cancelled->status);

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'contract.activated')
            ->where('subject_id', $dueMadrid->id)
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('pending', $activity->properties->get('from'));
        $this->assertSame('active', $activity->properties->get('to'));
    }

    public function test_idempotent_and_isolated(): void
    {
        $madrid = $this->makeSite('Europe/Madrid');
        [$unitA, $priceA] = $this->makeUnit($madrid);
        [$unitB, $priceB] = $this->makeUnit($madrid);

        $poisoned = $this->makePendingContract($unitA, $priceA, '2026-08-15');
        $healthy = $this->makePendingContract($unitB, $priceB, '2026-08-15');

        $result = (new ActivatePendingContracts(
            beforeActivate: function (int $id) use ($poisoned): void {
                if ($id === $poisoned->id) {
                    throw new \RuntimeException('poisoned activation');
                }
            },
        ))->run();

        $this->assertSame(1, $result['activated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['skipped']);

        $poisoned->refresh();
        $healthy->refresh();
        $this->assertSame(ContractStatus::Pending, $poisoned->status);
        $this->assertSame(ContractStatus::Active, $healthy->status);

        $this->assertTrue(
            SystemEvent::query()
                ->where('event', 'contract.activation.failed')
                ->where('subject_id', $poisoned->id)
                ->exists()
        );

        // Without the poison seam, the still-pending contract activates cleanly.
        $second = (new ActivatePendingContracts)->run();
        $this->assertSame(1, $second['activated']);
        $this->assertSame(0, $second['failed']);

        $poisoned->refresh();
        $this->assertSame(ContractStatus::Active, $poisoned->status);

        // Idempotent: nothing left to activate.
        $third = (new ActivatePendingContracts)->run();
        $this->assertSame(0, $third['activated']);
        $this->assertSame(0, $third['failed']);
    }

    private function makeSite(string $timezone): Site
    {
        return Site::factory()->create([
            'country_id' => $this->country->id,
            'currency' => 'EUR',
            'timezone' => $timezone,
            'legal_entity_id' => $this->entity->id,
        ]);
    }

    /**
     * @return array{0: Unit, 1: int}
     */
    private function makeUnit(Site $site): array
    {
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $this->employee->id,
            [
                'amount' => '100.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unitClass->update(['current_price_id' => $price->id]);

        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        return [$unit, $price->id];
    }

    private function makePendingContract(Unit $unit, int $priceId, string $moveInDate): Contract
    {
        $contact = Contact::factory()->create();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Pending,
            'move_in_date' => $moveInDate,
            'start_date' => $moveInDate,
            'billed_through' => null,
        ]);

        $contract->items()->create([
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $priceId,
            'effective_from' => $moveInDate,
            'effective_to' => null,
        ]);

        return $contract;
    }
}
