<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\AccessProviderName;
use App\Enums\AccessWebhookState;
use App\Enums\CredentialStatus;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\EsignProvider;
use App\Enums\EsignWebhookState;
use App\Enums\FiscalRegime;
use App\Enums\TaxIdType;
use App\Models\AccessProviderAccount;
use App\Models\CommunicationAccount;
use App\Models\Country;
use App\Models\DelinquencyPolicy;
use App\Models\Employee;
use App\Models\EsignProviderAccount;
use App\Models\Insurance;
use App\Models\InsuranceRate;
use App\Models\InvoiceSeries;
use App\Models\LegalEntity;
use App\Models\PaymentProviderAccount;
use App\Models\Price;
use App\Models\Site;
use App\Models\SiteSenderIdentity;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Models\User;
use App\Support\Billing\CurrencyGuard;
use App\Support\Communications\AccountScope;
use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Enums\PlaybookKind;
use App\Models\AircallUserLink;
use App\Models\Playbook;
use Database\Seeders\DiscountCatalogueSeeder;
use App\Support\Playbooks\PlaybookCompiler;
use Carbon\CarbonImmutable;
use Database\Seeders\ContractDocumentTemplateSeeder;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DebtPlaybookSeeder;
use Database\Seeders\DefaultAttributeLayoutSeeder;
use Database\Seeders\LeadChasePlaybookSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Empty facility stage for the demo world: geography, catalogue, templates,
 * playbooks, and fake provider accounts — no contacts/deals/contracts.
 */
class StageSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (Site::query()->where('code', 'MAD-01')->exists()) {
            $this->command?->info('Demo stage already present — skipping.');

            return;
        }

        $rngSeed = (int) (env('DEMO_SEED', env('SEED_RNG', 424242)));
        mt_srand($rngSeed);
        fake()->seed($rngSeed);

        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
        ]);

        $this->call(CountrySeeder::class);
        $this->call(DefaultAttributeLayoutSeeder::class);

        Employee::factory()->manager()->create([
            'name' => 'Demo Manager',
            'email' => 'manager@example.com',
        ]);
        // Remaining cast (ops / site managers / agents / accountant / read_only)
        // is assigned in DemoRbacGrants after sites exist.

        $manager = Employee::query()->withCompanyRole('owner')->firstOrFail();

        $spain = Country::query()->where('code', 'ES')->firstOrFail();
        $uk = Country::query()->where('code', 'GB')->firstOrFail();
        $france = Country::query()->where('code', 'FR')->firstOrFail();

        $legalEntity = LegalEntity::query()->firstOrCreate(
            ['tax_id' => 'PENDING-GESTOR'],
            [
                'legal_name' => 'PENDING GESTOR',
                'trading_name' => null,
                'tax_id_type' => TaxIdType::Nif,
                'vat_number' => null,
                'country_code' => 'ES',
                'address_line1' => 'Calle Placeholder 1',
                'address_line2' => null,
                'city' => 'Madrid',
                'postal_code' => '28001',
                'fiscal_regime' => FiscalRegime::None,
                'sepa_creditor_id' => null,
                'archived_at' => null,
            ]
        );
        InvoiceSeries::ensureDefaultsFor($legalEntity);

        PaymentProviderAccount::query()->firstOrCreate(
            [
                'legal_entity_id' => $legalEntity->id,
                'provider' => 'stripe',
                'is_active' => true,
            ],
            [
                'display_name' => 'Stripe',
                'publishable_key' => 'pk_test_seed_placeholder',
                'secret_key' => 'sk_test_seed_placeholder',
                'provider_account_id' => 'acct_test_seed',
                'account_token' => 'seed_account_token_'.str_pad((string) $legalEntity->id, 24, '0'),
                'status' => CredentialStatus::Connected,
                'last_error' => null,
            ]
        );

        $esPolicy = $this->seedDelinquencyPolicy('ES standard', [
            ['offset_days' => 5, 'action' => DelinquencyPolicyAction::AssessLateFee, 'params' => [
                'type' => 'percent', 'percent' => '10.00', 'cap_per_case' => '50.00',
            ]],
            ['offset_days' => 8, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                'notice_type' => 'overdue',
            ]],
            ['offset_days' => 8, 'action' => DelinquencyPolicyAction::RevokeAccess, 'params' => []],
            ['offset_days' => 12, 'action' => DelinquencyPolicyAction::PlaceOverlock, 'params' => []],
            ['offset_days' => 20, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                'notice_type' => 'final_demand',
            ]],
            ['offset_days' => 20, 'action' => DelinquencyPolicyAction::CreateTask, 'params' => [
                'title_key' => 'delinquency.task.final_demand', 'urgent' => true,
            ]],
        ]);

        $ukPolicy = $this->seedDelinquencyPolicy('UK standard', [
            ['offset_days' => 7, 'action' => DelinquencyPolicyAction::AssessLateFee, 'params' => [
                'type' => 'flat', 'amount' => '10.00',
            ]],
            ['offset_days' => 10, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                'notice_type' => 'overdue',
            ]],
            ['offset_days' => 10, 'action' => DelinquencyPolicyAction::RevokeAccess, 'params' => []],
            ['offset_days' => 14, 'action' => DelinquencyPolicyAction::PlaceOverlock, 'params' => []],
            ['offset_days' => 21, 'action' => DelinquencyPolicyAction::RecordNotice, 'params' => [
                'notice_type' => 'final_demand',
            ]],
            ['offset_days' => 21, 'action' => DelinquencyPolicyAction::CreateTask, 'params' => [
                'title_key' => 'delinquency.task.final_demand', 'urgent' => true,
            ]],
        ]);

        $siteDefs = [
            ['handle' => 'madrid', 'name' => 'Madrid Centro', 'code' => 'MAD-01', 'country_id' => $spain->id, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR', 'legal_entity_id' => $legalEntity->id, 'delinquency_policy_id' => $esPolicy->id],
            ['handle' => 'barcelona', 'name' => 'Barcelona Port', 'code' => 'BCN-01', 'country_id' => $spain->id, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR', 'legal_entity_id' => $legalEntity->id, 'delinquency_policy_id' => $esPolicy->id],
            ['handle' => 'valencia', 'name' => 'Valencia Norte', 'code' => 'VLC-01', 'country_id' => $spain->id, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR', 'legal_entity_id' => $legalEntity->id, 'delinquency_policy_id' => $esPolicy->id],
            ['handle' => 'london', 'name' => 'London East', 'code' => 'LON-01', 'country_id' => $uk->id, 'timezone' => 'Europe/London', 'currency' => 'GBP', 'legal_entity_id' => $legalEntity->id, 'delinquency_policy_id' => $ukPolicy->id],
            ['handle' => 'paris', 'name' => 'Paris Sud', 'code' => 'PAR-01', 'country_id' => $france->id, 'timezone' => 'Europe/Paris', 'currency' => 'EUR', 'legal_entity_id' => $legalEntity->id, 'delinquency_policy_id' => null],
        ];

        $sites = collect();
        foreach ($siteDefs as $def) {
            $handle = $def['handle'];
            unset($def['handle']);
            $site = Site::factory()->create($def);
            $sites->push($site);
            DemoWorld::current()?->remember('site.'.$handle, $site);
            DemoWorld::current()?->remember('site.'.strtolower($site->code), $site);
        }

        $vatFrom = CarbonImmutable::parse(CastExecutor::SIM_START)->subYear()->toDateString();
        foreach (
            [
                ['jurisdiction' => 'ES', 'rate' => '21.00', 'is_default' => true],
                ['jurisdiction' => 'FR', 'rate' => '20.00', 'is_default' => false],
                ['jurisdiction' => 'GB', 'rate' => '20.00', 'is_default' => false],
            ] as $vat
        ) {
            TaxRate::query()->create([
                'name' => 'VAT ('.$vat['jurisdiction'].')',
                'code' => 'vat',
                'rate' => $vat['rate'],
                'jurisdiction' => $vat['jurisdiction'],
                'is_default' => $vat['is_default'],
                'effective_from' => $vatFrom,
                'effective_to' => null,
                'created_by' => $manager->id,
            ]);
        }

        (new DiscountCatalogueSeeder)->run($manager);

        $unitClasses = collect();
        foreach (range(1, 10) as $n) {
            $unitClasses->push(UnitClass::factory()->create([
                'code' => "SS{$n}",
                'label' => "SS Unit {$n}",
                'size' => 5.00 + ($n - 1),
                'tax_rate_code' => 'vat',
            ]));
            $unitClasses->push(UnitClass::factory()->create([
                'code' => "AL{$n}",
                'label' => "AL Unit {$n}",
                'size' => 10.00 + ($n - 1) * 2,
                'tax_rate_code' => 'vat',
            ]));
        }

        $seededHistorical = false;
        foreach ($unitClasses as $unitClass) {
            $cataloguePrice = null;

            foreach ($sites as $site) {
                $rate = UnitClassRate::query()->create([
                    'unit_class_id' => $unitClass->id,
                    'site_id' => $site->id,
                ]);

                $from = now()->subMonths(6)->toDateString();
                $amount = fake()->randomFloat(2, 50, 300);

                if (! $seededHistorical) {
                    Price::query()->create([
                        'priceable_type' => 'unit_class_rate',
                        'priceable_id' => $rate->id,
                        'scope' => Price::SCOPE_CATALOGUE,
                        'amount' => round($amount * 0.9, 2),
                        'currency' => $site->currency,
                        'effective_from' => now()->subYear()->toDateString(),
                        'effective_to' => $from,
                        'created_by' => $manager->id,
                    ]);
                    $seededHistorical = true;
                }

                $price = Price::query()->create([
                    'priceable_type' => 'unit_class_rate',
                    'priceable_id' => $rate->id,
                    'scope' => Price::SCOPE_CATALOGUE,
                    'amount' => $amount,
                    'currency' => $site->currency,
                    'effective_from' => $from,
                    'effective_to' => null,
                    'created_by' => $manager->id,
                ]);

                CurrencyGuard::assertRateJunction($site->currency, $price->currency);

                $cataloguePrice ??= $price;
            }

            $unitClass->update(['current_price_id' => $cataloguePrice->id]);
        }

        foreach (
            [
                ['name' => 'Basic', 'coverage' => 3000, 'amount' => 3],
                ['name' => 'Premium', 'coverage' => 5000, 'amount' => 5],
            ] as $insuranceData
        ) {
            $insurance = Insurance::query()->create([
                'name' => $insuranceData['name'],
                'coverage' => $insuranceData['coverage'],
                'currency' => 'EUR',
                'tax_rate_code' => 'vat',
            ]);

            foreach ($sites as $site) {
                $rate = InsuranceRate::query()->create([
                    'insurance_id' => $insurance->id,
                    'site_id' => $site->id,
                ]);

                $price = Price::query()->create([
                    'priceable_type' => 'insurance_rate',
                    'priceable_id' => $rate->id,
                    'scope' => Price::SCOPE_CATALOGUE,
                    'amount' => $insuranceData['amount'],
                    'currency' => $site->currency,
                    'effective_from' => now()->subMonths(6)->toDateString(),
                    'effective_to' => null,
                    'created_by' => $manager->id,
                ]);

                CurrencyGuard::assertRateJunction($site->currency, $price->currency);
            }
        }

        // ~200 concurrent tenants across 5 sites; crowd RNG can pile onto one
        // class (cast personas also hard-require SS2–SS6 at Madrid late in the
        // window). 5/class was exhausting MAD-01 SS5 before Nadia/Ingrid/Amara.
        foreach ($unitClasses as $unitClass) {
            foreach ($sites as $site) {
                foreach (range(1, 20) as $n) {
                    // Direct create — UnitFactory::definition() still runs unique()
                    // even when unit_number is overridden, which exhausted A-###.
                    Unit::query()->create([
                        'site_id' => $site->id,
                        'unit_class_id' => $unitClass->id,
                        'unit_number' => sprintf('%s-%s-%02d', $site->code, $unitClass->code, $n),
                        'actual_width' => fake()->randomFloat(2, 1.5, 5.0),
                        'actual_depth' => fake()->randomFloat(2, 2.0, 6.0),
                        'actual_height' => fake()->randomFloat(2, 2.0, 3.5),
                        'enabled' => true,
                    ]);
                }
            }
        }

        $this->call(DebtPlaybookSeeder::class);
        $this->call(LeadChasePlaybookSeeder::class);
        $this->call(ContractDocumentTemplateSeeder::class);

        $this->seedFakeProviders($sites);
        $this->activateDemoPlaybooks();

        DemoRbacGrants::assign($sites);

        $this->command?->info("Demo stage seeded (DEMO_SEED={$rngSeed}).");
    }

    private function activateDemoPlaybooks(): void
    {
        foreach ([PlaybookKind::DebtProcess, PlaybookKind::LeadChase] as $kind) {
            $playbook = Playbook::query()
                ->where('kind', $kind)
                ->where('is_active', false)
                ->orderBy('id')
                ->first();

            if ($playbook === null) {
                $playbook = Playbook::query()
                    ->where('kind', $kind)
                    ->orderBy('id')
                    ->first();
            }

            if ($playbook === null) {
                continue;
            }

            $playbook->forceFill(['is_active' => true])->save();
            PlaybookCompiler::compile($playbook->fresh(['steps']) ?? $playbook);
        }
    }

    /**
     * @param  list<array{offset_days: int, action: DelinquencyPolicyAction, params: array<string, mixed>}>  $steps
     */
    private function seedDelinquencyPolicy(string $name, array $steps): DelinquencyPolicy
    {
        $policy = DelinquencyPolicy::query()->create([
            'name' => $name,
            'auto_release_overlock' => true,
            'auto_restore_access' => true,
        ]);

        foreach (array_values($steps) as $sort => $step) {
            $policy->steps()->create([
                'offset_days' => $step['offset_days'],
                'action' => $step['action'],
                'params' => $step['params'],
                'sort' => $sort,
            ]);
        }

        return $policy;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Site>  $sites
     */
    private function seedFakeProviders($sites): void
    {
        $email = CommunicationAccount::query()->firstOrCreate(
            [
                'channel' => Channel::Email,
                'provider' => Provider::Brevo,
                'scope' => AccountScope::Company,
            ],
            [
                'site_id' => null,
                'is_active' => true,
                'credentials' => ['api_key' => 'demo-brevo-key'],
                'webhook_url_token' => 'demo-brevo-webhook',
                'status' => CredentialStatus::Connected,
            ]
        );

        // Inactive: only one active company email account is allowed (Brevo above).
        // Still seeded so InboundInjector can enter at ProcessInboundWebhookEvent.
        $postmark = CommunicationAccount::query()->firstOrCreate(
            [
                'channel' => Channel::Email,
                'provider' => Provider::Postmark,
                'scope' => AccountScope::Company,
            ],
            [
                'site_id' => null,
                'is_active' => false,
                'credentials' => ['server_token' => 'demo-postmark-token'],
                'webhook_url_token' => 'demo-postmark-webhook',
                'status' => CredentialStatus::Connected,
            ]
        );

        $sms = CommunicationAccount::query()->firstOrCreate(
            [
                'channel' => Channel::Sms,
                'provider' => Provider::Twilio,
                'scope' => AccountScope::Company,
            ],
            [
                'site_id' => null,
                'is_active' => true,
                'credentials' => [
                    'account_sid' => 'ACdemo000000000000000000000000000',
                    'auth_token' => 'demo-twilio-token',
                    'messaging_service_sid' => '',
                ],
                'webhook_url_token' => 'demo-twilio-webhook',
                'status' => CredentialStatus::Connected,
            ]
        );

        $whatsapp = CommunicationAccount::query()->firstOrCreate(
            [
                'channel' => Channel::Whatsapp,
                'provider' => Provider::Sinch,
                'scope' => AccountScope::Company,
            ],
            [
                'site_id' => null,
                'is_active' => true,
                'credentials' => [
                    'project_id' => 'demo-proj',
                    'key_id' => 'demo-key-id',
                    'key_secret' => 'demo-key-secret',
                    'app_id' => 'demo-app',
                    'region' => 'us',
                ],
                'webhook_url_token' => 'demo-sinch-webhook',
                'status' => CredentialStatus::Connected,
            ]
        );

        $aircall = CommunicationAccount::query()->firstOrCreate(
            [
                'channel' => Channel::Call,
                'provider' => Provider::Aircall,
                'scope' => AccountScope::Company,
            ],
            [
                'site_id' => null,
                'is_active' => true,
                'credentials' => [
                    'api_id' => 'demo-aircall-id',
                    'api_token' => 'demo-aircall-token',
                ],
                'webhook_url_token' => 'demo-aircall-webhook',
                'status' => CredentialStatus::Connected,
            ]
        );

        foreach ($sites as $site) {
            SiteSenderIdentity::query()->firstOrCreate(
                ['site_id' => $site->id, 'channel' => Channel::Email],
                [
                    'account_id' => $email->id,
                    'from_name' => 'Unit HQ',
                    'from_email' => 'desk@example.com',
                    'reply_to_email' => 'reply@example.com',
                ]
            );
            SiteSenderIdentity::query()->firstOrCreate(
                ['site_id' => $site->id, 'channel' => Channel::Sms],
                [
                    'account_id' => $sms->id,
                    'from_number' => '+15550001111',
                ]
            );
            SiteSenderIdentity::query()->firstOrCreate(
                ['site_id' => $site->id, 'channel' => Channel::Whatsapp],
                [
                    'account_id' => $whatsapp->id,
                    'from_number' => '+15550009999',
                ]
            );
        }

        $manager = Employee::query()->withCompanyRole('owner')->orderBy('id')->first();
        if ($manager !== null) {
            AircallUserLink::query()->firstOrCreate(
                ['employee_id' => $manager->id],
                [
                    'aircall_user_id' => '456',
                    'aircall_user_label' => 'Demo Agent',
                ]
            );
        }

        $esign = EsignProviderAccount::query()->firstOrCreate(
            [
                'provider' => EsignProvider::Signable,
                'is_active' => true,
            ],
            [
                'display_name' => 'Signable Fake',
                'credentials' => ['api_key' => 'fake_key_demo'],
                'webhook_token' => Str::random(40),
                'webhook_state' => EsignWebhookState::Configured,
                'status' => CredentialStatus::Connected,
            ]
        );

        $access = AccessProviderAccount::query()->firstOrCreate(
            [
                'provider' => AccessProviderName::Sensorberg,
                'is_active' => true,
            ],
            [
                'display_name' => 'Sensorberg Fake',
                'credentials' => ['api_key' => 'fake_key_demo'],
                'webhook_token' => Str::random(40),
                'webhook_state' => AccessWebhookState::Configured,
                'status' => CredentialStatus::Connected,
            ]
        );

        $world = DemoWorld::current();
        if ($world !== null) {
            $world->remember('account.email', $email);
            $world->remember('account.postmark', $postmark);
            $world->remember('account.sms', $sms);
            $world->remember('account.whatsapp', $whatsapp);
            $world->remember('account.aircall', $aircall);
            $world->remember('account.esign', $esign);
            $world->remember('account.access', $access);
            $world->remember(
                'account.stripe',
                PaymentProviderAccount::query()->where('provider', 'stripe')->where('is_active', true)->first()
            );
        }
    }
}
