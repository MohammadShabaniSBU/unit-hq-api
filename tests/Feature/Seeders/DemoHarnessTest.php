<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\BillingAnchorModel;
use App\Enums\BillingInterval;
use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\CredentialStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\EsignEnvelopeStatus;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Enums\PaymentRequestStatus;
use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\AccessEvent;
use App\Models\AccessProviderAccount;
use App\Models\Charge;
use App\Models\CommunicationAccount;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\Country;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicy;
use App\Models\Employee;
use App\Models\EsignEnvelope;
use App\Models\EsignProviderAccount;
use App\Models\LegalEntity;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderAccount;
use App\Models\PaymentRequest;
use App\Models\Site;
use App\Models\TemplateFamily;
use App\Models\TemplateVariant;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\MessageDirection;
use App\Support\Communications\MessageSource;
use App\Support\Communications\MessageStatus;
use App\Support\Communications\Provider;
use App\Support\Delinquency\DelinquencyLifecycle;
use Carbon\CarbonImmutable;
use Database\Seeders\ContractDocumentTemplateSeeder;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class DemoHarnessTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DemoWorld::setCurrent(null);
        parent::tearDown();
    }

    public function test_clock_activation_billing_cure(): void
    {
        $employee = Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $entity = LegalEntity::factory()->create();
        $stripe = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $entity->id,
        ]);

        $policy = DelinquencyPolicy::factory()->create(['name' => 'Harness ES']);
        $policy->steps()->create([
            'offset_days' => 5,
            'action' => DelinquencyPolicyAction::AssessLateFee,
            'params' => ['type' => 'flat', 'amount' => '10.00'],
            'sort' => 0,
        ]);

        $site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'legal_entity_id' => $entity->id,
            'delinquency_policy_id' => $policy->id,
        ]);

        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $site->id,
            $employee->id,
            ['amount' => '100.00', 'currency' => 'EUR', 'effective_from' => '2026-01-01'],
        );
        $unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $contact = Contact::factory()->create();
        $world = new DemoWorld;
        DemoWorld::setCurrent($world);
        $world->remember('account.stripe', $stripe);
        $world->remember('site.madrid', $site);

        $contract = null;

        $clock = new DemoClock;
        $clock->run(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-15'),
            $world,
            function (CarbonImmutable $date, DemoWorld $w) use (
                &$contract,
                $contact,
                $unit,
                $price,
                $stripe,
            ): void {
                if ($date->toDateString() === '2026-08-01') {
                    $contract = Contract::factory()->create([
                        'contact_id' => $contact->id,
                        'currency' => 'EUR',
                        'status' => ContractStatus::Pending,
                        'billing_interval' => BillingInterval::Month,
                        'billing_interval_count' => 1,
                        'billing_anchor_model' => BillingAnchorModel::Anniversary,
                        'billing_anchor_date' => '2026-08-05',
                        'move_in_date' => '2026-08-05',
                        'start_date' => '2026-08-05',
                        // Cursor before move-in so billing:run on activation day advances a period.
                        'billed_through' => '2026-07-05',
                    ]);
                    $contract->items()->create([
                        'item_type' => 'unit',
                        'item_id' => $unit->id,
                        'price_id' => $price->id,
                        'effective_from' => '2026-08-05',
                        'effective_to' => null,
                    ]);
                    // First-period charge (signing would write this); due on anchor/move-in.
                    Charge::factory()->create([
                        'contract_id' => $contract->id,
                        'charge_type' => ChargeType::Rent,
                        'amount' => '100.00',
                        'net_amount' => '100.00',
                        'tax_amount' => '0.00',
                        'currency' => 'EUR',
                        'due_date' => '2026-08-05',
                        'period_start' => '2026-07-05',
                        'period_end' => '2026-08-05',
                    ]);
                    $w->remember('harness.contract', $contract);
                }

                if ($date->toDateString() === '2026-08-15' && $contract !== null) {
                    $contract->refresh();
                    $this->assertSame(ContractStatus::Active, $contract->status);
                    $this->assertGreaterThanOrEqual(
                        1,
                        Charge::query()->where('contract_id', $contract->id)->count(),
                    );

                    $case = DelinquencyLifecycle::open($contract);
                    $this->assertNotNull($case);

                    $openCharges = Charge::query()
                        ->where('contract_id', $contract->id)
                        ->get()
                        ->filter(fn (Charge $c) => bccomp($c->openAmount(), '0.00', 2) > 0);

                    $amount = '0.00';
                    $chargeIds = [];
                    foreach ($openCharges as $charge) {
                        $amount = bcadd($amount, $charge->openAmount(), 2);
                        $chargeIds[] = (int) $charge->id;
                    }

                    $request = PaymentRequest::factory()->create([
                        'contract_id' => $contract->id,
                        'payment_provider_account_id' => $stripe->id,
                        'charge_ids' => $chargeIds,
                        'amount' => $amount,
                        'currency' => 'EUR',
                        'status' => PaymentRequestStatus::Pending,
                        'expires_at' => now()->addDays(7),
                    ]);

                    // No outer transaction — afterCommit cure must fire in-step.
                    $w->stripe()->paymentSucceeded(
                        paymentIntentId: 'pi_harness_cure_1',
                        amount: $amount,
                        currency: 'EUR',
                        metadata: ['payment_request_id' => (string) $request->id],
                    );

                    $case->refresh();
                    $curedOn = $case->cured_on;
                    $this->assertNotNull($curedOn);
                    $this->assertSame(DelinquencyCureTrigger::Payment, $case->cure_trigger);
                    $this->assertSame('2026-08-15', $curedOn->toDateString());
                }
            },
        );

        $this->assertInstanceOf(Contract::class, $contract);
        $contract->refresh();
        $this->assertSame(ContractStatus::Active, $contract->status);
        $this->assertTrue(
            Delinquency::query()
                ->where('contract_id', $contract->id)
                ->whereNotNull('cured_on')
                ->exists(),
        );
    }

    public function test_five_injector_smokes(): void
    {
        $world = $this->bootLeanDemoWorld();

        // 1) Stripe setupSucceeded
        $contact = Contact::factory()->create();
        $setup = $world->stripe()->setupSucceeded($contact->id, 'pm_demo_smoke_1');
        $this->assertSame('processed', $setup->processing_status);
        $this->assertTrue(
            PaymentMethod::query()->where('stripe_pm_id', 'pm_demo_smoke_1')->exists()
        );

        // 2) Delivery
        $thread = MessageThread::query()->create([
            'contact_id' => $contact->id,
            'channel' => Channel::Email,
            'subject' => 'Smoke',
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);
        $message = Message::query()->create([
            'message_thread_id' => $thread->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
            'body_text' => 'Hi',
            'from_address' => 'desk@example.com',
            'to_address' => 'renter@example.com',
            'provider' => Provider::Brevo,
            'communication_account_id' => $world->emailAccount()->id,
            'provider_message_id' => '<demo-smoke@smtp-relay.mailin.fr>',
            'source' => MessageSource::Manual,
            'delivery_events' => [],
            'sent_at' => now(),
        ]);
        $delivery = $world->delivery()->event($message, 'delivered');
        $this->assertSame('processed', $delivery->processing_status);
        $this->assertSame(MessageStatus::Delivered, $message->fresh()->status);

        // 3) Inbound email
        $inbound = $world->inbound()->email('renter@example.com', 'Thanks, looking forward.');
        $this->assertContains($inbound->processing_status, ['processed', 'unmatched']);

        // 4) E-sign viewed
        $this->seed(ContractDocumentTemplateSeeder::class);
        $family = TemplateFamily::query()
            ->where('channel', TemplateChannel::Document)
            ->where('purpose', TemplatePurpose::Contract)
            ->firstOrFail();
        $variant = TemplateVariant::query()
            ->where('template_family_id', $family->id)
            ->firstOrFail();
        $contract = Contract::factory()->create([
            'contact_id' => $contact->id,
            'status' => ContractStatus::AwaitingSignature,
        ]);
        $doc = ContractDocument::query()->create([
            'contract_id' => $contract->id,
            'template_family_id' => $family->id,
            'template_variant_id' => $variant->id,
            'rendered_at' => now(),
            'pdf_path' => 'contracts/demo-smoke.pdf',
            'sha256' => hash('sha256', 'demo-smoke'),
            'status' => 'draft',
        ]);
        $envelope = EsignEnvelope::query()->create([
            'contract_id' => $contract->id,
            'contract_document_id' => $doc->id,
            'esign_provider_account_id' => $world->esignAccount()->id,
            'provider_envelope_ref' => 'fake-env-smoke-1',
            'signer_name' => 'Ada',
            'signer_email' => 'ada@example.com',
            'status' => EsignEnvelopeStatus::Sent,
            'expires_at' => now()->addDays(14),
            'sent_at' => now(),
            'post_cancellation' => false,
        ]);
        $esign = $world->esign()->viewed($envelope);
        $this->assertSame('processed', $esign->processing_status);
        $this->assertSame(EsignEnvelopeStatus::Viewed, $envelope->fresh()->status);

        // 5) Access door event
        $access = $world->access()->doorEvent('fake-gate-1', 'granted');
        $this->assertSame('processed', $access->processing_status);
        $this->assertTrue(
            AccessEvent::query()->where('provider_point_id', 'fake-gate-1')->exists()
        );
    }

    public function test_no_raw_inserts_grep(): void
    {
        $root = database_path('seeders/Demo');
        $this->assertDirectoryExists($root);

        $forbidden = [
            '/\bDB::table\s*\(/',
            '/\bDB::insert\s*\(/',
            '/\bDB::statement\s*\(\s*[\'"]INSERT/i',
            '/\bDB::unprepared\s*\(\s*[\'"]INSERT/i',
            '/->getConnection\(\)\s*->insert\s*\(/',
        ];

        $hits = [];
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = $file->getContents();
            foreach ($forbidden as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $hits[] = $file->getRelativePathname().' matches '.$pattern;
                }
            }
        }

        $this->assertSame([], $hits, "Raw inserts past the model layer are forbidden in Demo seeders:\n".implode("\n", $hits));
    }

    public function test_production_guard(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:seed')
            ->expectsOutputToContain('refuses to run in the production environment')
            ->assertFailed();
    }

    public function test_command_exposes_fresh_and_stays_off_database_seeder(): void
    {
        $command = $this->app->make(\App\Console\Commands\DemoSeedCommand::class);
        $this->assertTrue($command->getDefinition()->hasOption('fresh'));
        $this->assertTrue($command->getDefinition()->hasOption('cast-only'));

        $seeder = File::get(database_path('seeders/DatabaseSeeder.php'));
        $this->assertStringNotContainsString('Demo\\', $seeder);
        $this->assertStringNotContainsString('demo:seed', $seeder);
        $this->assertStringNotContainsString('DemoPipeline', $seeder);
    }

    private function bootLeanDemoWorld(): DemoWorld
    {
        $world = new DemoWorld;
        DemoWorld::setCurrent($world);

        $entity = LegalEntity::factory()->create();
        $stripe = PaymentProviderAccount::factory()->connected()->create([
            'legal_entity_id' => $entity->id,
        ]);
        $world->remember('account.stripe', $stripe);

        $email = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Brevo,
            'is_active' => true,
            'credentials' => ['api_key' => 'demo-brevo-key'],
            'webhook_url_token' => 'demo-brevo-webhook',
            'status' => CredentialStatus::Connected,
        ]);
        $world->remember('account.email', $email);

        // Inactive — company may only have one active email account (Brevo above).
        $postmark = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Email,
            'provider' => Provider::Postmark,
            'is_active' => false,
            'credentials' => ['server_token' => 'demo-postmark-token'],
            'webhook_url_token' => 'demo-postmark-webhook',
            'status' => CredentialStatus::Connected,
        ]);
        $world->remember('account.postmark', $postmark);

        $sms = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Sms,
            'provider' => Provider::Twilio,
            'is_active' => true,
            'credentials' => [
                'account_sid' => 'ACdemo',
                'auth_token' => 'tok',
                'messaging_service_sid' => '',
            ],
            'webhook_url_token' => 'demo-twilio-webhook',
            'status' => CredentialStatus::Connected,
        ]);
        $world->remember('account.sms', $sms);

        $whatsapp = CommunicationAccount::query()->create([
            'scope' => AccountScope::Company,
            'site_id' => null,
            'channel' => Channel::Whatsapp,
            'provider' => Provider::Sinch,
            'is_active' => true,
            'credentials' => [
                'project_id' => 'demo-proj',
                'key_id' => 'demo-key',
                'key_secret' => 'demo-secret',
                'app_id' => 'demo-app',
                'region' => 'us',
            ],
            'webhook_url_token' => 'demo-sinch-webhook',
            'status' => CredentialStatus::Connected,
        ]);
        $world->remember('account.whatsapp', $whatsapp);

        $esign = EsignProviderAccount::query()->create([
            'provider' => EsignProvider::Signable,
            'display_name' => 'Signable Fake',
            'credentials' => ['api_key' => 'fake_key_demo'],
            'webhook_token' => Str::random(40),
            'webhook_state' => EsignWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
        $world->remember('account.esign', $esign);

        $access = AccessProviderAccount::query()->create([
            'provider' => AccessProviderName::Sensorberg,
            'display_name' => 'Sensorberg Fake',
            'credentials' => ['api_key' => 'fake_key_demo'],
            'webhook_token' => Str::random(40),
            'webhook_state' => AccessWebhookState::Configured,
            'status' => CredentialStatus::Connected,
            'is_active' => true,
        ]);
        $world->remember('account.access', $access);

        return $world;
    }
}
