<?php

declare(strict_types=1);

namespace Tests\Feature\Discounts;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\MoveOutSettlement;
use App\Enums\TransferBilling;
use App\Enums\TransferPricingMode;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
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
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class EdgeTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    public function test_transfer_vacate_notice_zero_new_code(): void
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
            transferBilling: TransferBilling::NextPeriod->value,
            moveOutSettlement: MoveOutSettlement::Daily->value,
            turnoverHoldDays: 0,
        ));

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create(['tax_rate_code' => null]);
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '200.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);

        $destClass = UnitClass::factory()->create(['code' => 'S15', 'tax_rate_code' => null]);
        [, $destPrice] = $this->createUnitClassCataloguePrice(
            $destClass->id,
            $site->id,
            $employee->id,
            ['amount' => '300.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $destClass->update(['current_price_id' => $destPrice->id]);

        $discount = Discount::factory()->percent('20.00')->create(['tracks_rate_changes' => true]);

        // --- retain_rate: discounted contract price travels ---
        $originRetain = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $destRetain = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $destClass->id]);
        $retainContractId = (int) $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $originRetain->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated()->json('data.id');

        $originItem = ContractItem::query()
            ->with('price')
            ->where('contract_id', $retainContractId)
            ->whereNull('effective_to')
            ->firstOrFail();
        $this->assertSame('160.00', BillingMath::round2((string) $originItem->price->amount));

        $this->postJson("/api/contracts/{$retainContractId}/transfer", [
            'to_unit_id' => $destRetain->id,
            'transfer_date' => '2026-08-10',
            'pricing_mode' => TransferPricingMode::RetainRate->value,
        ])->assertOk();

        $retained = ContractItem::query()
            ->with('price')
            ->where('contract_id', $retainContractId)
            ->whereNull('effective_to')
            ->where('item_type', 'unit')
            ->firstOrFail();
        $this->assertSame($discount->id, $retained->discount_id);
        $this->assertSame('160.00', BillingMath::round2((string) $retained->price->amount));
        $this->assertSame($originItem->price_id, $retained->price_id);

        // --- destination_rate: provenance closed ---
        $originDest = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $destDest = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $destClass->id]);
        $destContractId = (int) $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $originDest->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$destContractId}/transfer", [
            'to_unit_id' => $destDest->id,
            'transfer_date' => '2026-08-10',
            'pricing_mode' => TransferPricingMode::DestinationRate->value,
        ])->assertOk();

        $destItem = ContractItem::query()
            ->with('price')
            ->where('contract_id', $destContractId)
            ->whereNull('effective_to')
            ->where('item_type', 'unit')
            ->firstOrFail();
        $this->assertNull($destItem->discount_id);
        $this->assertNull($destItem->base_rate);
        $this->assertSame($destPrice->id, $destItem->price_id);

        $closed = ContractItem::query()
            ->where('contract_id', $destContractId)
            ->whereNotNull('discount_id')
            ->where('discount_removed_reason', 'transfer')
            ->exists();
        $this->assertTrue($closed);

        // --- vacate mid-free-period: gap of a €0 version is €0 (no claw) ---
        // Settlement plans off the open tip; pin the open tip to the free segment
        // so composition is visible without rewriting VacateSettlement.
        $free = Discount::factory()->freeTime()->create();
        $vacateUnit = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $vacateContractId = (int) $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $free->id,
            'commitment_weeks' => 12,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $vacateUnit->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated()->json('data.id');

        $versions = ContractItem::query()
            ->with('price')
            ->where('contract_id', $vacateContractId)
            ->where('item_type', 'unit')
            ->orderBy('effective_from')
            ->get();
        $this->assertGreaterThanOrEqual(2, $versions->count());
        $freeSeg = $versions[0];
        $this->assertSame('0.00', BillingMath::round2((string) $freeSeg->price->amount));
        foreach ($versions->skip(1) as $later) {
            $later->forceFill([
                'effective_to' => $later->effective_from->toDateString(),
            ])->save();
        }
        $freeSeg->forceFill(['effective_to' => null])->save();

        $vacateContract = Contract::query()->findOrFail($vacateContractId);
        $vacateContract->forceFill([
            'billed_through' => '2026-08-03',
            'notice_period_days' => 0,
        ])->save();

        $this->postJson("/api/contracts/{$vacateContractId}/vacate", [
            'move_out_on' => '2026-08-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $gap = Charge::query()
            ->where('contract_id', $vacateContractId)
            ->where('charge_type', ChargeType::Rent)
            ->where('description', 'vacate.gap')
            ->first();
        // VacateSettlement skips zero-amount lines — no positive gap either.
        if ($gap !== null) {
            $this->assertSame('0.00', BillingMath::round2((string) $gap->net_amount));
        } else {
            $this->assertNull($gap);
        }

        // --- notice during discount: composes ---
        $noticeUnit = Unit::factory()->create(['site_id' => $site->id, 'unit_class_id' => $unitClass->id]);
        $noticeContractId = (int) $this->postJson('/api/contracts', [
            'contact_id' => Contact::factory()->create()->id,
            'start_date' => '2026-08-03',
            'move_in_date' => '2026-08-03',
            'deposit_amount' => '0.00',
            'discount_id' => $discount->id,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $noticeUnit->id,
                'amount' => '200.00',
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$noticeContractId}/notice", [
            'notice_given_on' => '2026-08-10',
            'scheduled_move_out_on' => '2026-09-10',
        ])->assertOk();

        $noticeContract = Contract::query()->findOrFail($noticeContractId);
        $this->assertSame(ContractStatus::NoticeGiven, $noticeContract->status);
        $openNotice = ContractItem::query()
            ->where('contract_id', $noticeContractId)
            ->whereNull('effective_to')
            ->firstOrFail();
        $this->assertSame($discount->id, $openNotice->discount_id);

        // Grep: zero new discount branches in billing / settlement / reports.
        foreach ([
            app_path('Support/Billing/VacateSettlement.php'),
            app_path('Support/Billing/TransferSettlement.php'),
            app_path('Support/Billing/RecurringBilling.php'),
            app_path('Support/Reports/OccupancyMetrics.php'),
        ] as $path) {
            $this->assertFileExists($path);
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'discount',
                strtolower($source),
                "Unexpected discount branch in {$path}",
            );
        }

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
