<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

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
use App\Support\Discounts\RecomputesDiscountedAmount;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class RecomputeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_tracking_nontracking_multiplier_promise(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00:00', 'Europe/Madrid'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00', 'Europe/Madrid'));

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

        // Pure helper: free-time multiplier promise.
        $free = Discount::factory()->freeTime()->create();
        $pure = RecomputesDiscountedAmount::recompute($free, '210.00', '100.00', '200.00');
        $this->assertSame('105.00', $pure['amount']);
        $this->assertSame('210.00', $pure['list_amount']);

        // Tracking percent: new list 210 → contract 168.
        $tracking = Discount::factory()->percent('20.00')->create(['tracks_rate_changes' => true]);
        $unitA = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $contactA = Contact::factory()->create();
        $createA = $this->postJson('/api/contracts', [
            'contact_id' => $contactA->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $tracking->id,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unitA->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated();
        $contractA = $createA->json('data.id');
        $openA = ContractItem::query()
            ->with('price')
            ->where('contract_id', $contractA)
            ->whereNull('effective_to')
            ->firstOrFail();
        $this->assertSame('160.00', BillingMath::round2((string) $openA->price->amount));

        $this->postJson("/api/contracts/{$contractA}/rate-changes", [
            'contract_item_id' => $openA->id,
            'new_amount' => '210.00',
            'effective_date' => '2026-08-03',
        ])->assertCreated()
            ->assertJsonPath('data.item.amount', '168.00');

        $successorA = ContractItem::query()
            ->with('price')
            ->where('contract_id', $contractA)
            ->whereNull('effective_to')
            ->firstOrFail();
        $this->assertSame('210.00', BillingMath::round2((string) $successorA->base_rate));
        $this->assertSame($tracking->id, $successorA->discount_id);

        $activity = Activity::query()
            ->where('subject_type', 'contract')
            ->where('subject_id', $contractA)
            ->where('description', 'contract.rate_scheduled')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('210.00', $activity->properties['list_amount'] ?? null);
        $this->assertSame('168.00', $activity->properties['contract_amount'] ?? null);
        $this->assertSame('20.00', $activity->properties['percent'] ?? null);

        // Non-tracking percent: plain list.
        $nonTracking = Discount::factory()->percent('20.00')->create(['tracks_rate_changes' => false]);
        $unitB = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $contactB = Contact::factory()->create();
        $createB = $this->postJson('/api/contracts', [
            'contact_id' => $contactB->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $nonTracking->id,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unitB->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated();
        $contractB = $createB->json('data.id');
        $openB = ContractItem::query()
            ->where('contract_id', $contractB)
            ->whereNull('effective_to')
            ->firstOrFail();

        $this->postJson("/api/contracts/{$contractB}/rate-changes", [
            'contract_item_id' => $openB->id,
            'new_amount' => '210.00',
            'effective_date' => '2026-08-03',
        ])->assertCreated()
            ->assertJsonPath('data.item.amount', '210.00');

        // Free-time: prior segments untouched; open tip uses multiplier (list → list × 1).
        $unitC = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $contactC = Contact::factory()->create();
        $createC = $this->postJson('/api/contracts', [
            'contact_id' => $contactC->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $free->id,
            'commitment_weeks' => 12,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $unitC->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated();
        $contractC = $createC->json('data.id');

        $before = ContractItem::query()
            ->with('price')
            ->where('contract_id', $contractC)
            ->where('item_type', 'unit')
            ->orderBy('effective_from')
            ->get();
        $this->assertGreaterThanOrEqual(2, $before->count());
        $firstAmount = BillingMath::round2((string) $before[0]->price->amount);
        $firstTo = $before[0]->effective_to?->toDateString();

        $openC = $before->first(fn (ContractItem $i): bool => $i->effective_to === null);
        $this->assertNotNull($openC);

        $this->postJson("/api/contracts/{$contractC}/rate-changes", [
            'contract_item_id' => $openC->id,
            'new_amount' => '210.00',
            'effective_date' => $openC->effective_from->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.item.amount', '210.00');

        $firstAfter = ContractItem::query()->with('price')->findOrFail($before[0]->id);
        $this->assertSame($firstAmount, BillingMath::round2((string) $firstAfter->price->amount));
        $this->assertSame($firstTo, $firstAfter->effective_to?->toDateString());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
