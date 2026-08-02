<?php

declare(strict_types=1);

namespace Tests\Support\Access;

use App\Enums\ContractStatus;
use App\Models\AccessPoint;
use App\Models\AccessProviderAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Access\AccessProviderRegistry;
use App\Support\Access\FakeAccessProvider;
use Carbon\Carbon;
use Tests\Support\CreatesCataloguePrices;

/**
 * Shared fixture for S15-02 reconciliation tests.
 */
trait AccessSyncTestSetup
{
    use CreatesCataloguePrices;

    private Site $site;

    private Unit $unit;

    private ?Unit $secondUnit = null;

    private AccessPoint $gate;

    private AccessPoint $door;

    private ?AccessPoint $secondDoor = null;

    private AccessProviderAccount $account;

    private Contact $contact;

    private Contract $contract;

    private int $priceId;

    private function setUpAccessSyncFixture(bool $withSecondUnit = false): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00', 'Europe/Madrid'));

        FakeAccessProvider::reset();
        $registry = app(AccessProviderRegistry::class);
        $registry->register('sensorberg', FakeAccessProvider::class);
        $registry->set(FakeAccessProvider::make(['api_key' => 'fake_ok']));

        $this->account = AccessProviderAccount::factory()->create([
            'provider' => 'sensorberg',
            'is_active' => true,
        ]);

        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
        $unitClass->update(['current_price_id' => $price->id]);

        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $this->gate = AccessPoint::factory()->gate()->create([
            'access_provider_account_id' => $this->account->id,
            'site_id' => $this->site->id,
            'provider_point_id' => 'fake-gate-1',
            'label' => 'Main gate',
        ]);
        $this->door = AccessPoint::factory()->unitDoor($this->unit->id)->create([
            'access_provider_account_id' => $this->account->id,
            'site_id' => $this->site->id,
            'provider_point_id' => 'fake-door-al6-06',
            'label' => 'Unit door A',
        ]);

        if ($withSecondUnit) {
            $this->secondUnit = Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $unitClass->id,
            ]);
            $this->secondDoor = AccessPoint::factory()->unitDoor($this->secondUnit->id)->create([
                'access_provider_account_id' => $this->account->id,
                'site_id' => $this->site->id,
                'provider_point_id' => 'fake-door-b',
                'label' => 'Unit door B',
            ]);
        }

        $this->contact = Contact::factory()->create([
            'email' => 'tenant@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->contract = Contract::factory()->create([
            'contact_id' => $this->contact->id,
            'status' => ContractStatus::Active,
            'start_date' => '2026-07-01',
            'move_in_date' => '2026-07-01',
            'billing_anchor_date' => '2026-07-01',
            'billed_through' => '2026-07-01',
        ]);

        $item = ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $this->contract->id,
            'contract_item_id' => $item->id,
            'started_on' => '2026-07-01',
            'ended_on' => null,
        ]);
    }

    private function tearDownAccessSyncFixture(): void
    {
        FakeAccessProvider::reset();
        app(AccessProviderRegistry::class)->set(null);
        Carbon::setTestNow();
    }
}
