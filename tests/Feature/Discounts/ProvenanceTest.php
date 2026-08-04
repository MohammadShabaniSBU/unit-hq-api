<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Models\Contact;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ProvenanceTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_versions_linked_writer_reused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'Europe/Madrid'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        \App\Models\Setting::setBilling(\App\Models\Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
            defaultDepositAmount: '0.00',
        ));

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '184.90', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $contact = Contact::factory()->create();
        $discount = Discount::factory()->freeTime()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'commitment_weeks' => 12,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '184.90',
            ]],
        ])->assertCreated();

        $contractId = $response->json('data.id');
        $versions = ContractItem::query()
            ->where('contract_id', $contractId)
            ->where('item_type', 'unit')
            ->orderBy('effective_from')
            ->get();

        $this->assertCount(3, $versions);
        foreach ($versions as $version) {
            $this->assertSame($discount->id, $version->discount_id);
            $this->assertSame('184.90', (string) $version->base_rate);
        }
        $this->assertSame($versions[0]->id, $versions[1]->supersedes_id);
        $this->assertSame($versions[1]->id, $versions[2]->supersedes_id);
        $this->assertSame('2026-09-28', $versions[0]->discount_ends_at?->toDateString()
            ?? $versions[2]->effective_from?->toDateString());

        // Grep guard: window closing lives only in the shared writer (+ ScheduleRateChange caller).
        $applies = file_get_contents(app_path('Support/Discounts/AppliesDiscountPlan.php'));
        $this->assertIsString($applies);
        $this->assertStringContainsString('WritesContractItemVersion::supersede', $applies);
        $this->assertStringNotContainsString("'effective_to'", $applies);
        $this->assertStringNotContainsString('effective_to =>', $applies);

        $schedule = file_get_contents(app_path('Support/Contracts/ScheduleRateChange.php'));
        $this->assertIsString($schedule);
        $this->assertStringContainsString('WritesContractItemVersion::supersede', $schedule);
        $this->assertStringNotContainsString("forceFill([\n                'effective_to'", $schedule);

        $writer = file_get_contents(app_path('Support/Contracts/WritesContractItemVersion.php'));
        $this->assertIsString($writer);
        $this->assertStringContainsString("'effective_to'", $writer);
        $this->assertStringContainsString("'supersedes_id'", $writer);

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
