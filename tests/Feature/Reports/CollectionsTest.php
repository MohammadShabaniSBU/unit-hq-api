<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AutopayAttemptStatus;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Models\Allocation;
use App\Models\AutopayAttempt;
use App\Models\CallWrapup;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Payment;
use App\Models\PaymentMethod as StoredPaymentMethod;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Reports\CollectionsReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class CollectionsTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private int $priceId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $policy = DelinquencyPolicy::factory()->create();
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $policy->id,
            'offset_days' => 10,
            'action' => DelinquencyPolicyAction::PlaceOverlock,
            'params' => [],
            'sort' => 1,
        ]);

        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'delinquency_policy_id' => $policy->id,
        ]);
        $this->unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_rates_promise_recovery_fixtures(): void
    {
        $kept = $this->makeContract('Kept', 'K-1');
        $broken = $this->makeContract('Broken', 'B-1');
        $autopayContract = $this->makeContract('Auto', 'A-1');

        // Charged 100 rent, allocated 80 → 80%
        $rentCharge = Charge::factory()->create([
            'contract_id' => $kept->id,
            'charge_type' => ChargeType::Rent,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-04-01',
            'created_at' => '2026-04-01 10:00:00',
        ]);
        $pay = Payment::factory()->cash('2026-04-05')->create([
            'contract_id' => $kept->id,
            'amount' => '80.00',
            'currency' => 'EUR',
            'created_at' => '2026-04-05 12:00:00',
        ]);
        $alloc80 = Allocation::query()->create([
            'payment_id' => $pay->id,
            'charge_id' => $rentCharge->id,
            'amount' => '80.00',
        ]);
        Allocation::query()->whereKey($alloc80->id)->update(['created_at' => '2026-04-05 12:00:00']);

        // Late fee charged 20, allocated 20 → 100%
        $fee = Charge::factory()->create([
            'contract_id' => $broken->id,
            'charge_type' => ChargeType::LateFee,
            'amount' => '20.00',
            'net_amount' => '20.00',
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => '2026-04-10',
        ]);
        Charge::query()->whereKey($fee->id)->update(['created_at' => '2026-04-10 10:00:00']);
        Charge::query()->whereKey($rentCharge->id)->update(['created_at' => '2026-04-01 10:00:00']);

        $feePay = Payment::factory()->cash('2026-04-12')->create([
            'contract_id' => $broken->id,
            'amount' => '20.00',
            'currency' => 'EUR',
        ]);
        Payment::query()->whereKey($feePay->id)->update(['created_at' => '2026-04-12 12:00:00']);
        $feeAlloc = Allocation::query()->create([
            'payment_id' => $feePay->id,
            'charge_id' => $fee->id,
            'amount' => '20.00',
        ]);
        Allocation::query()->whereKey($feeAlloc->id)->update(['created_at' => '2026-04-12 12:00:00']);

        // Promise kept: wrap-up then allocation within 7 days
        $this->makePromise($kept->contact, '2026-05-01 09:00:00');
        $keptPay = Payment::factory()->cash('2026-05-03')->create([
            'contract_id' => $kept->id,
            'amount' => '10.00',
            'currency' => 'EUR',
        ]);
        Payment::query()->whereKey($keptPay->id)->update(['created_at' => '2026-05-03 10:00:00']);
        $keptAlloc = Allocation::query()->create([
            'payment_id' => $keptPay->id,
            'charge_id' => $rentCharge->id,
            'amount' => '10.00',
        ]);
        Allocation::query()->whereKey($keptAlloc->id)->update(['created_at' => '2026-05-03 10:00:00']);

        // Promise broken: wrap-up, no allocation in window
        $this->makePromise($broken->contact, '2026-05-10 09:00:00');

        // Autopay: first attempt failed, later collected
        $pm = StoredPaymentMethod::factory()->create(['contact_id' => $autopayContract->contact_id]);
        AutopayAttempt::factory()->failed()->create([
            'contract_id' => $autopayContract->id,
            'payment_method_id' => $pm->id,
            'amount' => '50.00',
            'currency' => 'EUR',
            'attempted_at' => '2026-05-01 08:00:00',
            'resolved_at' => '2026-05-01 08:01:00',
            'status' => AutopayAttemptStatus::Failed,
        ]);
        Payment::factory()->create([
            'contract_id' => $autopayContract->id,
            'amount' => '50.00',
            'currency' => 'EUR',
            'method' => \App\Enums\PaymentMethod::StripeCard,
            'received_on' => '2026-05-04',
            'created_at' => '2026-05-04 11:00:00',
        ]);

        // Days-to-cure + overlock correlation
        $curedWith = Delinquency::factory()->create([
            'contract_id' => $kept->id,
            'delinquency_policy_id' => $this->site->delinquency_policy_id,
            'anchor_due_date' => '2026-04-01',
            'opened_on' => '2026-04-01',
            'cured_on' => '2026-04-11',
            'cure_trigger' => DelinquencyCureTrigger::Payment,
        ]);
        DelinquencyStep::query()->create([
            'delinquency_id' => $curedWith->id,
            'action' => DelinquencyStepAction::PlaceOverlock,
            'executed_on' => '2026-04-05',
            'trigger' => DelinquencyStepTrigger::Ladder,
        ]);

        Delinquency::factory()->create([
            'contract_id' => $broken->id,
            'delinquency_policy_id' => $this->site->delinquency_policy_id,
            'anchor_due_date' => '2026-04-01',
            'opened_on' => '2026-04-01',
            'cured_on' => '2026-04-06',
            'cure_trigger' => DelinquencyCureTrigger::Payment,
        ]);

        $report = new CollectionsReport;
        $result = $report->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            from: '2026-04-01',
            to: '2026-06-30',
        ));

        $rentRow = collect($result->rows)->firstWhere('charge_type', 'rent');
        $this->assertNotNull($rentRow);
        $this->assertSame('100.00', $rentRow['charged']);
        $this->assertSame('90.00', $rentRow['allocated']);
        $this->assertSame(90.0, $rentRow['rate']);

        $feeRow = collect($result->rows)->firstWhere('charge_type', 'late_fee');
        $this->assertNotNull($feeRow);
        $this->assertSame('20.00', $feeRow['charged']);
        $this->assertSame('20.00', $feeRow['allocated']);
        $this->assertSame(100.0, $feeRow['rate']);

        $promise = $result->meta['promise_kept'];
        $this->assertSame(2, $promise['promised']);
        $this->assertSame(1, $promise['kept']);
        $this->assertSame(1, $promise['broken']);
        $this->assertSame(50.0, $promise['kept_rate']);
        $this->assertSame(7, $result->meta['promise_window_days']);
        $this->assertCount(1, $promise['broken_contracts']);
        $this->assertSame($broken->id, $promise['broken_contracts'][0]['contract_id']);

        $autopay = $result->meta['autopay'];
        $this->assertSame(1, $autopay['failed']);
        $this->assertSame(1, $autopay['recovered']);
        $this->assertSame(100.0, $autopay['recovery_rate']);

        $this->assertSame(2, $result->meta['days_to_cure']['cured_count']);
        $this->assertSame(7.5, $result->meta['days_to_cure']['average_days']); // (10+5)/2

        $corr = $result->meta['overlock_correlation'];
        $this->assertSame(1, $corr['with_overlock']);
        $this->assertSame(1, $corr['without_overlock']);
        $this->assertStringContainsString('Correlation', $corr['caveat']);
        $this->assertTrue(
            collect($result->meta['notes'])->contains(
                static fn (string $n): bool => str_contains($n, 'correlation'),
            ),
        );

        $api = $this->getJson(
            '/api/reports/collections?from=2026-04-01&to=2026-06-30&site_ids[]='.$this->site->id,
        );
        $api->assertOk();
        $api->assertJsonPath('data.meta.promise_kept.kept', 1);
    }

    private function makeContract(string $name, string $unitNumber): Contract
    {
        $contact = Contact::factory()->create([
            'first_name' => $name,
            'last_name' => 'Tenant',
        ]);
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $unitNumber,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'move_in_date' => '2026-01-01',
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->priceId,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        return $contract->fresh(['contact']) ?? $contract;
    }

    private function makePromise(Contact $contact, string $at): void
    {
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Call,
            'channel_key' => '+1555000',
            'last_message_at' => $at,
            'unread_count' => 0,
        ]);
        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Received,
            'body_text' => 'Call',
            'from_address' => '+15550001111',
            'to_address' => '+15551112222',
            'source' => MessageSource::System,
            'sent_at' => $at,
            'created_at' => $at,
        ]);
        $wrapup = CallWrapup::query()->create([
            'message_id' => $message->id,
            'disposition' => 'payment_promised',
            'note' => 'Will pay',
            'employee_id' => $this->employee->id,
        ]);
        CallWrapup::query()->whereKey($wrapup->id)->update([
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
