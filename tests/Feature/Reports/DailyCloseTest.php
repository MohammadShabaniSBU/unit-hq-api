<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ContractStatus;
use App\Enums\PaymentMethod;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\RecordsActivity;
use App\Support\Reports\DailyCloseReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DailyCloseTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employeeA;

    private Employee $employeeB;

    private Site $site;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Madrid'));

        $this->employeeA = Employee::factory()->manager()->create(['name' => 'Alice Desk']);
        $this->employeeB = Employee::factory()->manager()->create(['name' => 'Bob Desk']);
        Sanctum::actingAs($this->employeeA);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employeeA->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
            'unit_number' => 'C-1',
        ]);
        $this->contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
        ]);
        ContractItem::query()->create([
            'contract_id' => $this->contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $price->id,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_messy_day_nets(): void
    {
        $day = '2026-06-15';

        // Cash ×3 across two employees: 50 + 30 (Alice) + 20 (Bob) = 100
        $cash1 = $this->manualPayment('50.00', PaymentMethod::Cash, $day, $this->employeeA);
        $cash2 = $this->manualPayment('30.00', PaymentMethod::Cash, $day, $this->employeeA);
        $cash3 = $this->manualPayment('20.00', PaymentMethod::Cash, $day, $this->employeeB);

        // One reversal of Alice's 30 → net cash drawer = 50+20+(-30) = 40? Wait: 50+30+20-30 = 70
        $reversal = Payment::query()->create([
            'contract_id' => $this->contract->id,
            'amount' => '-30.00',
            'currency' => 'EUR',
            'method' => PaymentMethod::Cash,
            'received_on' => $day,
            'reference' => 'REV-30',
            'stripe_payment_intent_id' => null,
            'idempotency_key' => 'manual:'.Str::uuid(),
            'reversal_of_payment_id' => $cash2->id,
        ]);
        RecordsActivity::core('payment.reversed', $reversal, [
            'amount' => '-30.00',
            'method' => 'cash',
        ], $this->employeeA);

        // Autopay (system)
        Payment::query()->create([
            'contract_id' => $this->contract->id,
            'amount' => '100.00',
            'currency' => 'EUR',
            'method' => PaymentMethod::StripeCard,
            'received_on' => $day,
            'reference' => null,
            'stripe_payment_intent_id' => 'pi_autopay_messy',
            'idempotency_key' => 'autopay:'.Str::uuid(),
            'reversal_of_payment_id' => null,
        ]);

        // Link payment (system)
        Payment::query()->create([
            'contract_id' => $this->contract->id,
            'amount' => '75.00',
            'currency' => 'EUR',
            'method' => PaymentMethod::StripeCard,
            'received_on' => $day,
            'reference' => 'link-ref',
            'stripe_payment_intent_id' => 'pi_link_messy',
            'idempotency_key' => 'link:'.Str::uuid(),
            'reversal_of_payment_id' => null,
        ]);

        $report = new DailyCloseReport;
        $result = $report->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: $day,
        ));

        // Drawer = cash net = 50+30+20-30 = 70
        $this->assertSame('70.00', $result->meta['cash_subtotal']);
        $this->assertSame('70.00', $result->meta['headlines']['cash_subtotal']['amount']);
        $this->assertSame('drawer_number', $result->meta['headlines']['cash_subtotal']['label']);

        $aliceCash = collect($result->rows)->first(
            static fn (array $r): bool => $r['method'] === 'cash' && $r['employee'] === 'Alice Desk',
        );
        $this->assertNotNull($aliceCash);
        $this->assertSame('50.00', $aliceCash['net_amount']); // 50+30-30
        $this->assertSame(3, $aliceCash['payment_count']);

        $bobCash = collect($result->rows)->first(
            static fn (array $r): bool => $r['method'] === 'cash' && $r['employee'] === 'Bob Desk',
        );
        $this->assertNotNull($bobCash);
        $this->assertSame('20.00', $bobCash['net_amount']);

        $stripe = collect($result->rows)->first(
            static fn (array $r): bool => $r['method'] === 'stripe_card' && $r['employee'] === 'system',
        );
        $this->assertNotNull($stripe);
        $this->assertSame('175.00', $stripe['net_amount']);
        $this->assertStringContainsString('pi_autopay_messy', $stripe['provider_refs']);
        $this->assertStringContainsString('pi_link_messy', $stripe['provider_refs']);

        $reversalRowAmount = collect($result->rows)
            ->where('method', 'cash')
            ->sum(static fn (array $r): float => (float) $r['net_amount']);
        $this->assertSame(70.0, $reversalRowAmount);

        $this->assertTrue(
            collect($result->meta['notes'])->contains(
                static fn (string $n): bool => str_contains($n, 'drawer number'),
            ),
        );

        $api = $this->getJson('/api/reports/daily-close?as_of='.$day.'&site_ids[]='.$this->site->id);
        $api->assertOk();
        $api->assertJsonPath('data.meta.cash_subtotal', '70.00');

        // unused vars silence — ensure seeded
        $this->assertNotNull($cash1);
        $this->assertNotNull($cash3);
    }

    private function manualPayment(
        string $amount,
        PaymentMethod $method,
        string $receivedOn,
        Employee $causer,
    ): Payment {
        $payment = Payment::query()->create([
            'contract_id' => $this->contract->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'method' => $method,
            'received_on' => $receivedOn,
            'reference' => 'R-'.$amount,
            'stripe_payment_intent_id' => null,
            'idempotency_key' => 'manual:'.Str::uuid(),
            'reversal_of_payment_id' => null,
        ]);

        RecordsActivity::core('payment.recorded', $payment, [
            'amount' => $amount,
            'method' => $method->value,
        ], $causer);

        return $payment;
    }
}
