<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContractItemChangeReason;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ContractItemVersionTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private Unit $unit;

    private Unit $unitB;

    private UnitClassRate $rate;

    private Price $cataloguePrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create(['country_id' => $country->id, 'currency' => 'EUR']);
        $this->unitClass = UnitClass::factory()->create();
        [$this->rate, $this->cataloguePrice] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '196.72', 'effective_from' => '2026-01-01'],
        );
        $this->unitClass->update(['current_price_id' => $this->cataloguePrice->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
        $this->unitB = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
        ]);
    }

    public function test_signing_creates_open_version(): void
    {
        $contact = Contact::factory()->create();
        $moveIn = '2026-07-01';

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '196.72',
            ]],
        ]);

        $response->assertCreated();
        $item = ContractItem::query()->findOrFail($response->json('data.items.0.id'));
        $this->assertSame($moveIn, $item->effective_from->toDateString());
        $this->assertNull($item->effective_to);
        $this->assertNull($item->change_reason);
    }

    public function test_items_on_returns_one_version_per_subject(): void
    {
        $contract = $this->makeContractWithVersions();

        foreach (['2026-07-01', '2026-07-14', '2026-08-01'] as $date) {
            $items = $contract->itemsOn(CarbonImmutable::parse($date));
            $this->assertCount(1, $items);
            $this->assertSame($this->unit->id, $items->first()->item_id);
        }
    }

    public function test_adjacent_versions_are_contiguous(): void
    {
        $contract = $this->makeContractWithVersions();
        $versions = $contract->items()->where('item_type', 'unit')->orderBy('effective_from')->get();

        $this->assertCount(2, $versions);
        $this->assertSame(
            $versions[0]->effective_to->toDateString(),
            $versions[1]->effective_from->toDateString(),
        );
        $this->assertSame($versions[0]->id, $versions[1]->supersedes_id);
    }

    public function test_multi_unit_contract_allowed(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'move_in_date' => '2026-07-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unitB->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-07-01',
            'effective_to' => null,
        ]);

        $this->assertCount(2, $contract->itemsOn(CarbonImmutable::parse('2026-07-15')));
    }

    public function test_charges_retain_superseded_item_reference(): void
    {
        $contract = $this->makeContractWithVersions();
        $original = $contract->items()->whereNotNull('effective_to')->firstOrFail();

        $charge = Charge::factory()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $original->id,
            'currency' => 'EUR',
        ]);

        $this->assertSame($original->id, $charge->fresh()->contract_item_id);
    }

    public function test_future_version_not_returned_today(): void
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'move_in_date' => '2026-07-01',
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-08-01',
        ]);

        $futurePrice = Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $this->rate->id,
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => '215.00',
            'currency' => 'EUR',
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $futurePrice->id,
            'effective_from' => '2026-08-01',
            'effective_to' => null,
            'change_reason' => ContractItemChangeReason::RateChange,
        ]);

        $today = $contract->itemsOn(CarbonImmutable::parse('2026-07-15'));
        $this->assertCount(1, $today);
        $this->assertSame('196.72', (string) $today->first()->price->amount);
    }

    public function test_every_version_has_price_id(): void
    {
        $this->assertFalse(Schema::hasColumn('contract_items', 'amount'));

        $contact = Contact::factory()->create();
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-01',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '196.72',
            ]],
        ]);
        $response->assertCreated();
        $item = ContractItem::query()->findOrFail($response->json('data.items.0.id'));
        $this->assertNotNull($item->price_id);
    }

    public function test_signing_references_catalogue_price(): void
    {
        $contact = Contact::factory()->create();
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-01',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '196.72',
            ]],
        ]);
        $response->assertCreated();
        $this->assertSame($this->cataloguePrice->id, $response->json('data.items.0.price_id'));
        // Matching catalogue amount — no contract-scoped copy row.
        $this->assertSame(0, Price::query()->where('scope', Price::SCOPE_CONTRACT)->count());
    }

    public function test_catalogue_change_does_not_alter_contract(): void
    {
        $contact = Contact::factory()->create();
        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-07-01',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => '196.72',
            ]],
        ]);
        $response->assertCreated();
        $itemId = $response->json('data.items.0.id');

        $this->cataloguePrice->update(['effective_to' => '2026-07-15']);
        Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $this->rate->id,
            'scope' => Price::SCOPE_CATALOGUE,
            'amount' => '250.00',
            'currency' => 'EUR',
            'effective_from' => '2026-07-15',
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        $item = ContractItem::query()->with('price')->findOrFail($itemId);
        $this->assertSame($this->cataloguePrice->id, $item->price_id);
        $this->assertSame('196.72', (string) $item->price->amount);
    }

    private function makeContractWithVersions(): Contract
    {
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'move_in_date' => '2026-07-01',
        ]);

        $v1 = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $this->cataloguePrice->id,
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-08-01',
        ]);

        $successorPrice = Price::query()->create([
            'priceable_type' => 'unit_class_rate',
            'priceable_id' => $this->rate->id,
            'scope' => Price::SCOPE_CONTRACT,
            'amount' => '215.00',
            'currency' => 'EUR',
            'effective_from' => null,
            'effective_to' => null,
            'created_by' => $this->employee->id,
        ]);

        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $this->unit->id,
            'price_id' => $successorPrice->id,
            'effective_from' => '2026-08-01',
            'effective_to' => null,
            'supersedes_id' => $v1->id,
            'change_reason' => ContractItemChangeReason::RateChange,
        ]);

        return $contract->fresh();
    }
}
