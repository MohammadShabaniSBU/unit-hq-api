<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Models\AdConversionEvent;
use App\Models\AdConversionMapping;
use App\Models\AdPlatformAccount;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Advertising\AdPlatform;
use App\Support\Advertising\ConversionEventKey;
use App\Support\Advertising\ConversionOutbox;
use App\Support\Advertising\ConversionValueRule;
use App\Support\Billing\BillingMath;
use App\Support\Contracts\ContractSigning;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\AuthenticatesAsEmployee;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class ConversionOutboxTest extends TestCase
{
    use AuthenticatesAsEmployee;
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29-access']),
            'https://googleads.googleapis.com/*' => Http::response(['results' => []]),
            'https://graph.facebook.com/*' => Http::response(['events_received' => 1]),
        ]);

        $this->employee = $this->authenticateAsEmployee();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 10:00:00', 'Europe/Madrid'));

        $country = Country::factory()->create(['code' => 'ES']);
        $this->site = Site::factory()->create([
            'country_id' => $country->id,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
        ]);
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '100.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);
        $this->contact = Contact::factory()->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function signing_writes_the_event_inside_the_transaction_and_rollback_leaves_nothing(): void
    {
        $this->mapMoveIn(ConversionValueRule::None);
        $create = $this->postJson('/api/contracts', $this->payload('0.00'))->assertCreated();
        $contract = Contract::query()->findOrFail($create->json('data.id'));
        $this->assertSame(ContractStatus::AwaitingSignature, $contract->status);
        $this->assertSame(0, AdConversionEvent::query()->count());

        try {
            DB::transaction(function () use ($contract): void {
                ContractSigning::complete($contract, null, $this->employee->id);
                throw new RuntimeException('force rollback');
            });
            $this->fail('Expected the rollback exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(0, AdConversionEvent::query()->count());

        DB::transaction(function () use ($contract): void {
            ContractSigning::complete($contract->fresh(), null, $this->employee->id);
        });

        $event = AdConversionEvent::query()->firstOrFail();
        $this->assertSame(ConversionEventKey::MoveIn, $event->event_key);
        $this->assertSame('contract:'.$contract->id.':move_in', $event->dedupe_id);
        $this->assertSame('contract', $event->subject_type);
        $this->assertSame($contract->id, $event->subject_id);
        $this->assertNull($event->value);
        $this->assertNull($event->currency);
    }

    #[Test]
    public function a_second_record_does_not_duplicate_and_lead_ignores_value(): void
    {
        $this->accountWith(ConversionEventKey::Lead, ConversionValueRule::None, 'customers/1/conversionActions/2');
        $deal = Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $this->site->id,
        ]);

        $this->assertSame(1, AdConversionEvent::query()->count());

        ConversionOutbox::record(
            ConversionEventKey::Lead,
            $deal,
            $this->contact,
            $this->site,
            '10.00',
            'EUR',
            CarbonImmutable::now(),
        );

        $event = AdConversionEvent::query()->firstOrFail();
        $this->assertSame(1, AdConversionEvent::query()->count());
        $this->assertSame('deal:'.$deal->id.':lead', $event->dedupe_id);
        $this->assertSame('deal', $event->subject_type);
        $this->assertNull($event->value);
        $this->assertNull($event->currency);
    }

    #[Test]
    public function no_active_mapping_writes_nothing(): void
    {
        Deal::factory()->create([
            'contact_id' => $this->contact->id,
            'site_id' => $this->site->id,
        ]);
        Reservation::factory()->create(['contact_id' => $this->contact->id]);

        $this->assertSame(0, AdConversionEvent::query()->count());
    }

    #[Test]
    public function a_reservation_create_writes_one_event(): void
    {
        $this->accountWith(ConversionEventKey::Reservation, ConversionValueRule::None, 'Schedule', AdPlatform::Meta);
        $reservation = Reservation::factory()->create(['contact_id' => $this->contact->id]);

        $event = AdConversionEvent::query()->firstOrFail();
        $this->assertSame('reservation:'.$reservation->id.':created', $event->dedupe_id);
        $this->assertSame('reservation', $event->subject_type);
        $this->assertNull($event->value);
    }

    #[Test]
    public function move_in_value_sums_first_period_revenue_and_excludes_the_deposit(): void
    {
        $this->mapMoveIn(ConversionValueRule::FirstPeriodNet);
        $create = $this->postJson('/api/contracts', $this->payload('25.00', '100.00', 'immediate'))->assertCreated();
        $contractId = (int) $create->json('data.id');

        $charges = Charge::query()->where('contract_id', $contractId)->get();
        $deposit = $charges->first(function (Charge $charge): bool {
            $type = $charge->charge_type instanceof ChargeType
                ? $charge->charge_type
                : ChargeType::from((string) $charge->charge_type);

            return $type === ChargeType::Deposit;
        });
        $this->assertNotNull($deposit);

        $revenue = '0.00';
        $all = '0.00';
        foreach ($charges as $charge) {
            $all = BillingMath::round2(bcadd($all, (string) $charge->net_amount, 8));
            $type = $charge->charge_type instanceof ChargeType
                ? $charge->charge_type
                : ChargeType::from((string) $charge->charge_type);
            if ($type->isRevenue()) {
                $revenue = BillingMath::round2(bcadd($revenue, (string) $charge->net_amount, 8));
            }
        }

        $event = AdConversionEvent::query()->where('dedupe_id', 'contract:'.$contractId.':move_in')->firstOrFail();
        $this->assertSame(0, BillingMath::cmp((string) $event->value, $revenue, 2));
        $this->assertNotSame(0, BillingMath::cmp((string) $event->value, $all, 2));
        $this->assertSame('EUR', $event->currency);
    }

    #[Test]
    public function a_free_first_period_stores_zero(): void
    {
        $unitClass = UnitClass::factory()->create();
        [, $price] = $this->createUnitClassCataloguePrice(
            $unitClass->id,
            $this->site->id,
            $this->employee->id,
            ['amount' => '0.00', 'effective_from' => '2026-01-01'],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $freeUnit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        $this->mapMoveIn(ConversionValueRule::FirstPeriodNet);
        $create = $this->postJson('/api/contracts', [
            'contact_id' => $this->contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => '0.00',
            'signature_mode' => 'immediate',
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $freeUnit->id,
                'amount' => '0.00',
            ]],
        ])->assertCreated();

        $event = AdConversionEvent::query()
            ->where('dedupe_id', 'contract:'.$create->json('data.id').':move_in')
            ->firstOrFail();
        $this->assertSame('0.00', (string) $event->value);
        $this->assertSame('EUR', $event->currency);
    }

    private function mapMoveIn(ConversionValueRule $rule): void
    {
        $this->accountWith(
            ConversionEventKey::MoveIn,
            $rule,
            'customers/1234567890/conversionActions/9',
        );
    }

    private function accountWith(
        ConversionEventKey $key,
        ConversionValueRule $rule,
        string $ref,
        AdPlatform $provider = AdPlatform::GoogleAds,
    ): AdPlatformAccount {
        $account = AdPlatformAccount::factory()->create([
            'provider' => $provider,
            'currency' => 'EUR',
            'timezone' => 'Europe/Madrid',
            'is_active' => true,
        ]);
        AdConversionMapping::factory()->create([
            'ad_platform_account_id' => $account->id,
            'event_key' => $key,
            'external_ref' => $ref,
            'value_rule' => $rule,
            'is_active' => true,
        ]);

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $deposit, string $amount = '100.00', string $mode = 'remote'): array
    {
        return [
            'contact_id' => $this->contact->id,
            'start_date' => '2026-07-10',
            'move_in_date' => '2026-07-10',
            'deposit_amount' => $deposit,
            'signature_mode' => $mode,
            'items' => [[
                'item_type' => 'unit',
                'item_id' => $this->unit->id,
                'amount' => $amount,
            ]],
        ];
    }
}
