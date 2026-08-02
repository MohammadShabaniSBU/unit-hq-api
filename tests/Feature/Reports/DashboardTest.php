<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\AutopayAttemptStatus;
use App\Enums\ChargeType;
use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Enums\DepositPayoutStatus;
use App\Enums\DepositSettlementOutcome;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\EsignEnvelopeStatus;
use App\Models\AccessProviderAccount;
use App\Models\AutopayAttempt;
use App\Models\Charge;
use App\Models\CommunicationAccount;
use App\Models\CommsTriage;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\DelinquencyPolicyStep;
use App\Models\DepositSettlement;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Models\EsignProviderAccount;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Support\Billing\BillingMath;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Delinquency\DelinquencyLifecycle;
use App\Support\Reports\AgeingReport;
use App\Support\Reports\DashboardReport;
use App\Support\Reports\MovementReport;
use App\Support\Reports\OccupancyMetrics;
use App\Support\Reports\OccupancyReport;
use App\Support\Reports\RentRollReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Database\Seeders\ContractDocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private UnitClass $unitClass;

    private int $priceId;

    private DelinquencyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();

        $this->policy = DelinquencyPolicy::factory()->create(['name' => 'dash-policy']);
        DelinquencyPolicyStep::query()->create([
            'delinquency_policy_id' => $this->policy->id,
            'offset_days' => 3,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 1,
        ]);

        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'name' => 'Madrid Hub',
            'delinquency_policy_id' => $this->policy->id,
        ]);

        $this->unitClass = UnitClass::factory()->create([
            'code' => 'S10',
            'label' => 'Small 10',
            'size' => '10.00',
        ]);
        [, $price] = $this->createUnitClassCataloguePrice(
            $this->unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->priceId = (int) $price->id;
        $this->unitClass->update(['current_price_id' => $this->priceId]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cards_equal_reports(): void
    {
        $this->seedOccupiedUnit('A-101', '2026-01-01', null);
        $this->seedOccupiedUnit('A-102', '2026-06-01', null);
        $this->seedOccupiedUnit('A-103', '2025-01-01', '2026-06-10');
        $this->seedOverdueCase('O-1', '2026-05-01', '50.00');

        $filters = new ReportFilters(siteIds: [$this->site->id], asOf: '2026-06-15');
        $dashboard = (new DashboardReport)->runBounded($filters);
        $cards = $dashboard->meta['cards'];

        $occ = (new OccupancyReport)->run($filters);
        $this->assertSame(
            $occ->meta['headlines']['unit']['rate'],
            $cards['occupancy']['value'],
        );
        $this->assertSame(
            $occ->meta['headlines']['economic']['rate'],
            $cards['occupancy']['secondary']['economic_rate'],
        );

        $rent = (new RentRollReport)->run($filters);
        $this->assertSame($rent->meta['footer']['monthly_rent'], $cards['monthly_rent']['value']);
        $this->assertSame($rent->meta['footer']['currency'], $cards['monthly_rent']['currency']);

        $ageing = (new AgeingReport)->run($filters);
        $this->assertSame($ageing->meta['totals_by_currency'][0]['amount'], $cards['overdue']['value']);
        $this->assertSame(count($ageing->rows), $cards['overdue']['secondary']['contract_count']);

        $movement = (new MovementReport)->run(new ReportFilters(
            siteIds: [$this->site->id],
            from: '2026-06-01',
            to: '2026-06-15',
        ));
        $expectedNet = (int) $movement->meta['identity']['move_ins']
            - (int) $movement->meta['identity']['move_outs'];
        $this->assertSame($expectedNet, $cards['movement_net']['value']);
        $this->assertSame((int) $movement->meta['identity']['move_ins'], $cards['movement_net']['secondary']['move_ins']);
        $this->assertSame((int) $movement->meta['identity']['move_outs'], $cards['movement_net']['secondary']['move_outs']);

        $openCases = Delinquency::query()
            ->where('opened_on', '<=', '2026-06-15')
            ->where(function ($q): void {
                $q->whereNull('cured_on')->orWhere('cured_on', '>', '2026-06-15');
            })
            ->count();
        $this->assertSame($openCases, $cards['open_delinquency_cases']['value']);

        $api = $this->getJson('/api/reports/dashboard?as_of=2026-06-15&site_ids[]='.$this->site->id);
        $api->assertOk();
        $this->assertEquals(
            $cards['occupancy']['value'],
            $api->json('data.meta.cards.occupancy.value'),
        );
        $api->assertJsonPath('data.meta.trends.collections.axis', 'zero_based');
    }

    public function test_deltas_month_boundary(): void
    {
        $this->seedOccupiedUnit('D-101', '2026-01-01', null);
        $this->seedOccupiedUnit('D-102', '2026-06-01', null);

        $filters = new ReportFilters(siteIds: [$this->site->id], asOf: '2026-06-15');
        $dashboard = (new DashboardReport)->runBounded($filters);
        $cards = $dashboard->meta['cards'];

        $current = OccupancyMetrics::snapshot('2026-06-15', [$this->site->id]);
        $prior = OccupancyMetrics::snapshot('2026-05-15', [$this->site->id]);
        $expectedOccDelta = round(($current['unit_rate'] ?? 0.0) - ($prior['unit_rate'] ?? 0.0), 1);
        $this->assertSame($expectedOccDelta, $cards['occupancy']['delta']);

        $rentNow = (new RentRollReport)->run(new ReportFilters(siteIds: [$this->site->id], asOf: '2026-06-15'));
        $rentThen = (new RentRollReport)->run(new ReportFilters(siteIds: [$this->site->id], asOf: '2026-05-15'));
        $expectedRentDelta = BillingMath::round2(bcsub(
            (string) $rentNow->meta['footer']['monthly_rent'],
            (string) $rentThen->meta['footer']['monthly_rent'],
            2,
        ));
        $this->assertSame($expectedRentDelta, $cards['monthly_rent']['delta']);
        $this->assertSame('2026-05-15', $dashboard->meta['prior_as_of']);
    }

    public function test_attention_row_live(): void
    {
        $contract = $this->seedOccupiedUnit('ATT-1', '2026-01-01', null);

        AutopayAttempt::factory()->create([
            'contract_id' => $contract->id,
            'status' => AutopayAttemptStatus::Failed,
            'attempted_at' => now()->subDay(),
        ]);

        $account = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => true,
            'credentials' => ['server_token' => 'dash-token'],
            'webhook_url_token' => 'dash-webhook',
            'status' => CredentialStatus::Connected,
        ]);
        CommsTriage::query()->create([
            'communication_account_id' => $account->id,
            'provider' => Provider::Postmark,
            'provider_message_id' => 'dash-triage-1',
            'channel' => Channel::Email,
            'sender_value' => 'parked@example.com',
            'preview' => ['from' => 'parked@example.com', 'subject' => 'Hi', 'body_text' => 'x', 'channel' => 'email'],
            'payload' => ['MessageID' => 'dash-triage-1'],
            'status' => 'pending',
        ]);

        AccessProviderAccount::query()->create([
            'provider' => AccessProviderName::Sensorberg,
            'display_name' => 'Dash Access',
            'credentials' => ['api_key' => 'x'],
            'webhook_token' => Str::random(40),
            'webhook_state' => AccessWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
            'sync_attention' => [
                'drift_denied_but_granted' => [[
                    'contract_id' => $contract->id,
                    'occurred_at' => CarbonImmutable::now()->subDay()->toIso8601String(),
                ]],
            ],
        ]);

        $ended = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Ended,
            'move_in_date' => '2025-01-01',
            'deposit_amount' => '200.00',
        ]);
        ContractItem::query()->create([
            'contract_id' => $ended->id,
            'item_type' => 'unit',
            'item_id' => Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $this->unitClass->id,
                'unit_number' => 'DEP-1',
                'enabled' => true,
            ])->id,
            'price_id' => $this->priceId,
            'effective_from' => '2025-01-01',
            'effective_to' => null,
        ]);
        DepositSettlement::query()->create([
            'contract_id' => $ended->id,
            'outcome' => DepositSettlementOutcome::Released,
            'deposit_amount' => '200.00',
            'refunded_amount' => '200.00',
            'currency' => 'EUR',
            'payout_status' => DepositPayoutStatus::Pending,
            'created_by' => $this->employee->id,
        ]);

        $this->seed(ContractDocumentTemplateSeeder::class);
        $family = TemplateFamily::query()
            ->where('channel', TemplateChannel::Document)
            ->where('purpose', TemplatePurpose::Contract)
            ->firstOrFail();
        $variant = TemplateVariant::query()
            ->where('template_family_id', $family->id)
            ->firstOrFail();

        $esign = EsignProviderAccount::query()->create([
            'provider' => EsignProvider::Signable,
            'display_name' => 'Dash Signable',
            'credentials' => ['api_key' => 'fake'],
            'webhook_token' => Str::random(40),
            'webhook_state' => EsignWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);

        $awaiting = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::AwaitingSignature,
            'move_in_date' => '2026-07-01',
        ]);
        $doc = ContractDocument::query()->create([
            'contract_id' => $awaiting->id,
            'template_family_id' => $family->id,
            'template_variant_id' => $variant->id,
            'rendered_at' => now(),
            'pdf_path' => 'contracts/dash-awaiting.pdf',
            'sha256' => hash('sha256', 'dash-awaiting'),
            'status' => 'draft',
        ]);
        EsignEnvelope::query()->create([
            'contract_id' => $awaiting->id,
            'contract_document_id' => $doc->id,
            'esign_provider_account_id' => $esign->id,
            'provider_envelope_ref' => 'env-expiring',
            'signer_name' => 'Ada',
            'signer_email' => 'ada@example.com',
            'status' => EsignEnvelopeStatus::Sent,
            'expires_at' => CarbonImmutable::now()->addDays(2),
            'sent_at' => CarbonImmutable::now()->subDay(),
            'post_cancellation' => false,
            'created_by' => $this->employee->id,
        ]);

        $postCancel = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Cancelled,
            'move_in_date' => '2026-01-01',
        ]);
        $doc2 = ContractDocument::query()->create([
            'contract_id' => $postCancel->id,
            'template_family_id' => $family->id,
            'template_variant_id' => $variant->id,
            'rendered_at' => now(),
            'pdf_path' => 'contracts/dash-post.pdf',
            'sha256' => hash('sha256', 'dash-post'),
            'status' => 'draft',
        ]);
        EsignEnvelope::query()->create([
            'contract_id' => $postCancel->id,
            'contract_document_id' => $doc2->id,
            'esign_provider_account_id' => $esign->id,
            'provider_envelope_ref' => 'env-post',
            'signer_name' => 'Bob',
            'signer_email' => 'bob@example.com',
            'status' => EsignEnvelopeStatus::Signed,
            'expires_at' => CarbonImmutable::now()->addDays(30),
            'sent_at' => CarbonImmutable::now()->subDays(5),
            'signed_at' => CarbonImmutable::now()->subDay(),
            'post_cancellation' => true,
            'created_by' => $this->employee->id,
        ]);

        $dashboard = (new DashboardReport)->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            asOf: '2026-06-15',
        ));

        /** @var array<string, array{key: string, count: int, to: string, filters: array<string, mixed>}> $byKey */
        $byKey = [];
        foreach ($dashboard->meta['attention'] as $chip) {
            $byKey[$chip['key']] = $chip;
        }

        $this->assertCount(6, $byKey);

        $this->assertSame(1, $byKey['failed_autopay']['count']);
        $this->assertSame('/billing/delinquency', $byKey['failed_autopay']['to']);

        $chips = Contract::attentionCounts();
        $this->assertSame($chips['drift_denied_but_granted_count'], $byKey['drift_denied_but_granted']['count']);
        $this->assertSame(1, $byKey['drift_denied_but_granted']['count']);
        $this->assertSame('/leasing/contracts', $byKey['drift_denied_but_granted']['to']);
        $this->assertSame('drift_denied_but_granted', $byKey['drift_denied_but_granted']['filters']['attention']);

        $this->assertSame($chips['post_cancellation_count'], $byKey['signed_after_cancellation']['count']);
        $this->assertSame(1, $byKey['signed_after_cancellation']['count']);
        $this->assertSame('post_cancellation', $byKey['signed_after_cancellation']['filters']['attention']);

        $this->assertSame(1, $byKey['triage']['count']);
        $this->assertSame('/inbox', $byKey['triage']['to']);

        $this->assertSame(1, $byKey['expiring_signatures']['count']);
        $this->assertSame('/leasing/contracts', $byKey['expiring_signatures']['to']);

        $this->assertSame(1, $byKey['pending_deposit_payouts']['count']);
        $this->assertSame('/insights/deposit-liability', $byKey['pending_deposit_payouts']['to']);
    }

    private function seedOccupiedUnit(string $unitNumber, string $startedOn, ?string $endedOn): Contract
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $unitNumber,
            'enabled' => true,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'currency' => 'EUR',
            'status' => $endedOn !== null ? ContractStatus::Ended : ContractStatus::Active,
            'move_in_date' => $startedOn,
            'deposit_amount' => '100.00',
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'item_type' => 'unit',
            'item_id' => $unit->id,
            'price_id' => $this->priceId,
            'effective_from' => $startedOn,
            'effective_to' => null,
        ]);
        UnitOccupancy::query()->create([
            'unit_id' => $unit->id,
            'contract_id' => $contract->id,
            'started_on' => $startedOn,
            'ended_on' => $endedOn,
            'ended_reason' => $endedOn !== null ? ContractEndedReason::Vacated->value : null,
        ]);

        return $contract;
    }

    private function seedOverdueCase(string $unitNumber, string $dueDate, string $amount): void
    {
        $unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $this->unitClass->id,
            'unit_number' => $unitNumber,
            'enabled' => true,
        ]);
        $contract = Contract::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
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
        Charge::factory()->create([
            'contract_id' => $contract->id,
            'charge_type' => ChargeType::Rent,
            'amount' => $amount,
            'net_amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'EUR',
            'due_date' => $dueDate,
        ]);
        $contract = $contract->fresh(['unitItem.item.site', 'charges.allocations']) ?? $contract;
        DelinquencyLifecycle::openOrFail($contract);
    }
}
