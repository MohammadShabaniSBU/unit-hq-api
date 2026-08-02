<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\ContractDocumentStatus;
use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Enums\DealStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Enums\PlaybookKind;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Models\EsignProviderAccount;
use App\Models\LegalEntity;
use App\Models\Offer;
use App\Models\OfferOption;
use App\Models\Playbook;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\UnitClass;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use App\Support\Reports\FunnelReport;
use App\Support\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Database\Seeders\ContractDocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class FunnelTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private int $unitClassRateId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($this->employee);

        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'legal_entity_id' => $entity->id,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
        ]);

        $unitClass = UnitClass::factory()->create(['size' => '10.00']);
        [$rate] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '80.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $this->unitClassRateId = (int) $rate->id;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cohort_stages_and_medians(): void
    {
        // Cohort: 5 deals created in May.
        // Pipeline: 5 deals → 4 sent → 3 viewed → 2 accepted → 2 signed (1 walk-in, 1 remote)
        // Medians on known gaps (deal→sent = 2 days for all sent).

        $d1 = $this->makeDeal('2026-05-01 10:00:00');
        $d2 = $this->makeDeal('2026-05-02 10:00:00');
        $d3 = $this->makeDeal('2026-05-03 10:00:00');
        $d4 = $this->makeDeal('2026-05-04 10:00:00');
        $d5 = $this->makeDeal('2026-05-05 10:00:00'); // never offered

        $o1 = $this->makeOffer($d1, sentAt: '2026-05-03 10:00:00', viewedAt: '2026-05-05 10:00:00', acceptedAt: '2026-05-07 10:00:00');
        $o2 = $this->makeOffer($d2, sentAt: '2026-05-04 10:00:00', viewedAt: '2026-05-06 10:00:00', acceptedAt: '2026-05-08 10:00:00');
        $this->makeOffer($d3, sentAt: '2026-05-05 10:00:00', viewedAt: '2026-05-07 10:00:00', acceptedAt: null);
        $this->makeOffer($d4, sentAt: '2026-05-06 10:00:00', viewedAt: null, acceptedAt: null);

        $this->makeSignedContract($d1, '2026-05-09 10:00:00', remote: false);
        $this->makeSignedContract($d2, '2026-05-10 10:00:00', remote: true);

        // Options on accepted offers for band stats
        OfferOption::query()->create([
            'offer_id' => $o1->id,
            'unit_class_rate_id' => $this->unitClassRateId,
            'label' => 'Mid',
            'display_order' => 0,
            'selected_at' => '2026-05-07 10:00:00',
        ]);
        OfferOption::query()->create([
            'offer_id' => $o2->id,
            'unit_class_rate_id' => $this->unitClassRateId,
            'label' => 'Mid',
            'display_order' => 0,
            'selected_at' => '2026-05-08 10:00:00',
        ]);

        $result = (new FunnelReport)->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            from: '2026-05-01',
            to: '2026-05-31',
        ));

        $byStage = collect($result->rows)->keyBy('stage');
        $this->assertSame(5, $byStage['deals']['count']);
        $this->assertSame(4, $byStage['offers_sent']['count']);
        $this->assertSame(3, $byStage['offers_viewed']['count']);
        $this->assertSame(2, $byStage['accepted']['count']);
        $this->assertSame(2, $byStage['contracts_signed']['count']);

        // deal→sent gaps: 2,2,2,2 days → median 2
        $this->assertSame(2.0, $result->meta['median_days']['deal_to_sent']);
        // sent→viewed: 2,2,2 → median 2
        $this->assertSame(2.0, $result->meta['median_days']['sent_to_viewed']);
        // viewed→accepted: 2,2 → median 2
        $this->assertSame(2.0, $result->meta['median_days']['viewed_to_accepted']);
        // accepted→signed: 2,2 → median 2
        $this->assertSame(2.0, $result->meta['median_days']['accepted_to_signed']);

        $this->assertSame(1, $result->meta['signature_split']['walk_in']);
        $this->assertSame(1, $result->meta['signature_split']['remote']);
        $this->assertTrue($result->meta['lead_chase']['correlation_caveat']);

        $this->getJson('/api/reports/funnel?from=2026-05-01&to=2026-05-31&site_ids[]='.$this->site->id)
            ->assertOk()
            ->assertJsonPath('data.rows.0.count', 5);

        $this->assertNotNull($d5->id);
    }

    public function test_enrolment_consistency_with_s09(): void
    {
        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Funnel chase',
            'is_active' => true,
            'enrolment_filters' => [],
        ]);

        $automation = Automation::query()->create([
            'name' => 'Funnel chase v1',
            'status' => AutomationStatus::Active,
            'version' => 1,
            'playbook_id' => $playbook->id,
        ]);
        $playbook->update(['automation_id' => $automation->id]);

        $enrolledA = $this->makeDeal('2026-05-10 10:00:00');
        $enrolledB = $this->makeDeal('2026-05-11 10:00:00');
        $notEnrolled = $this->makeDeal('2026-05-12 10:00:00');

        AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'subject_type' => 'deal',
            'subject_id' => $enrolledA->id,
            'status' => AutomationRunStatus::Waiting,
            'depth' => 0,
        ]);
        AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'subject_type' => 'deal',
            'subject_id' => $enrolledB->id,
            'status' => AutomationRunStatus::Succeeded,
            'depth' => 0,
            'completed_at' => now(),
        ]);

        $lineageCount = PlaybookEnrolmentSummary::lineageQuery((int) $playbook->id)
            ->where('subject_type', 'deal')
            ->whereIn('subject_id', [$enrolledA->id, $enrolledB->id, $notEnrolled->id])
            ->count();

        $result = (new FunnelReport)->runBounded(new ReportFilters(
            siteIds: [$this->site->id],
            from: '2026-05-01',
            to: '2026-05-31',
        ));

        $this->assertSame(2, $lineageCount);
        $this->assertSame(2, $result->meta['lead_chase']['enrolled_deals']);
        $this->assertSame(1, $result->meta['lead_chase']['not_enrolled_deals']);
        $this->assertSame($playbook->id, $result->meta['lead_chase']['playbook_id']);
        $this->assertTrue($result->meta['lead_chase']['correlation_caveat']);

        // S09 enrolments endpoint total for the same lineage
        $api = $this->getJson('/api/playbooks/'.$playbook->id.'/enrolments?per_page=50');
        $api->assertOk();
        $api->assertJsonPath('meta.total', 2);
    }

    private function makeDeal(string $createdAt): Deal
    {
        $deal = Deal::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'site_id' => $this->site->id,
            'status' => DealStatus::New,
        ]);
        $deal->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $deal->fresh();
    }

    private function makeOffer(
        Deal $deal,
        ?string $sentAt,
        ?string $viewedAt,
        ?string $acceptedAt,
    ): Offer {
        $status = 'draft';
        if ($acceptedAt !== null) {
            $status = 'accepted';
        } elseif ($viewedAt !== null) {
            $status = 'viewed';
        } elseif ($sentAt !== null) {
            $status = 'sent';
        }

        return Offer::factory()->create([
            'deal_id' => $deal->id,
            'contact_id' => $deal->contact_id,
            'status' => $status,
            'sent_at' => $sentAt,
            'first_viewed_at' => $viewedAt,
            'accepted_at' => $acceptedAt,
            'expires_at' => '2026-07-01 00:00:00',
        ]);
    }

    private function makeSignedContract(Deal $deal, string $signedAt, bool $remote): Contract
    {
        $contract = Contract::factory()->create([
            'contact_id' => $deal->contact_id,
            'deal_id' => $deal->id,
            'currency' => 'EUR',
            'status' => ContractStatus::Active,
            'signed_at' => $signedAt,
            'move_in_date' => '2026-06-01',
            'start_date' => '2026-06-01',
        ]);

        if ($remote) {
            $this->seed(ContractDocumentTemplateSeeder::class);
            $family = TemplateFamily::query()
                ->where('channel', TemplateChannel::Document)
                ->where('purpose', TemplatePurpose::Contract)
                ->firstOrFail();
            $variant = TemplateVariant::query()
                ->where('template_family_id', $family->id)
                ->firstOrFail();

            $document = ContractDocument::query()->create([
                'contract_id' => $contract->id,
                'template_family_id' => $family->id,
                'template_variant_id' => $variant->id,
                'rendered_at' => now(),
                'pdf_path' => 'contracts/test-'.$contract->id.'.pdf',
                'sha256' => hash('sha256', 'funnel-test'),
                'status' => ContractDocumentStatus::Draft,
            ]);

            $account = EsignProviderAccount::query()->create([
                'provider' => EsignProvider::Signable,
                'display_name' => 'Funnel Fake',
                'credentials' => ['api_key' => 'fake_key_funnel'],
                'webhook_token' => Str::random(40),
                'webhook_state' => EsignWebhookState::Configured,
                'status' => CredentialStatus::Connected,
                'is_active' => true,
            ]);

            EsignEnvelope::query()->create([
                'contract_id' => $contract->id,
                'contract_document_id' => $document->id,
                'esign_provider_account_id' => $account->id,
                'provider_envelope_ref' => 'env-'.$contract->id,
                'signer_name' => 'Test Signer',
                'signer_email' => 'signer@example.com',
                'status' => EsignEnvelopeStatus::Signed,
                'expires_at' => now()->addDays(7),
                'sent_at' => now()->subDay(),
                'signed_at' => $signedAt,
                'created_by' => $this->employee->id,
            ]);
        }

        return $contract;
    }
}
