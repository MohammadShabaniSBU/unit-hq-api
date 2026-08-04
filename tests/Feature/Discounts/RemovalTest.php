<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Enums\ContractItemChangeReason;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Discount;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Billing\BillingMath;
use App\Support\Discounts\RemovesDiscount;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RemovalTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_boundary_collapse_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'Europe/Madrid'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'Europe/Madrid'));

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        Setting::setBilling(Setting::billing()->with(
            defaultBillingInterval: 'week',
            defaultBillingIntervalCount: 4,
            billingAnchorModel: 'anniversary',
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
            ['amount' => '200.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $discount = Discount::factory()->freeTime()->create();
        $contact = Contact::factory()->create();

        $create = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'commitment_weeks' => 12,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unit->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated();

        $contractId = (int) $create->json('data.id');
        $contract = \App\Models\Contract::query()->findOrFail($contractId);

        // Reason required.
        $this->deleteJson("/api/contracts/{$contractId}/discount", [])
            ->assertStatus(422);

        $versionsBefore = ContractItem::query()
            ->with('price')
            ->where('contract_id', $contractId)
            ->where('item_type', 'unit')
            ->orderBy('effective_from')
            ->get();
        $this->assertGreaterThanOrEqual(3, $versionsBefore->count());

        $today = CarbonImmutable::parse('2026-08-10');
        $boundary = RemovesDiscount::nextPeriodBoundary($contract, $today);
        // Mid first free period (4-week anniversary from 2026-08-03 → 2026-08-31).
        $this->assertSame('2026-08-31', $boundary);

        $response = $this->deleteJson("/api/contracts/{$contractId}/discount", [
            'reason' => 'Customer requested full rate',
        ])->assertOk();

        $this->assertSame('2026-08-31', $response->json('data.boundary'));

        $open = ContractItem::query()
            ->with('price')
            ->where('contract_id', $contractId)
            ->whereNull('effective_to')
            ->firstOrFail();

        $this->assertSame('200.00', BillingMath::round2((string) $open->price->amount));
        $this->assertNull($open->discount_id);
        $this->assertSame(ContractItemChangeReason::DiscountRemoved, $open->change_reason);
        $this->assertSame('2026-08-31', $open->effective_from->toDateString());

        // Future free/partial segments collapsed (zero-length).
        $future = ContractItem::query()
            ->where('contract_id', $contractId)
            ->where('item_type', 'unit')
            ->where('effective_from', '>=', $boundary)
            ->where('id', '!=', $open->id)
            ->get();
        foreach ($future as $version) {
            $this->assertSame(
                $version->effective_from->toDateString(),
                $version->effective_to?->toDateString(),
                'Future discounted segment should be zero-length after removal',
            );
        }

        $stamped = ContractItem::query()
            ->where('contract_id', $contractId)
            ->whereNotNull('discount_id')
            ->whereNotNull('discount_removed_at')
            ->count();
        $this->assertGreaterThan(0, $stamped);

        $activity = Activity::query()
            ->where('subject_type', 'contract')
            ->where('subject_id', $contractId)
            ->where('description', 'contract.discount_removed')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('Customer requested full rate', $activity->properties['reason'] ?? null);
        $this->assertSame('200.00', $activity->properties['list_amount'] ?? null);
        $this->assertSame('2026-08-31', $activity->properties['effective_date'] ?? null);

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
