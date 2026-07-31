<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Enums\MoveOutSettlement;
use App\Enums\ReservationStatus;
use App\Models\Charge;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Country;
use App\Models\DepositSettlement;
use App\Models\Employee;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use App\Support\Billing\RevenueByCurrency;
use App\Support\Occupancy\Availability;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesCataloguePrices;
use Tests\TestCase;

class VacateTest extends TestCase
{
    use CreatesCataloguePrices;
    use RefreshDatabase;

    private Employee $employee;

    private Site $site;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Europe/Madrid'));

        $this->employee = Employee::factory()->manager()->create();

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
            [
                'amount' => '310.00',
                'currency' => 'EUR',
                'effective_from' => '2026-01-01',
            ],
        );
        $unitClass->update(['current_price_id' => $price->id]);
        $this->unit = Unit::factory()->create([
            'site_id' => $this->site->id,
            'unit_class_id' => $unitClass->id,
        ]);

        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: '100.00',
            moveOutSettlement: MoveOutSettlement::None->value,
            turnoverHoldDays: 0,
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_notice_end_dates_occupancy(): void
    {
        $contract = $this->signContract('2026-06-01');

        $response = $this->postJson("/api/contracts/{$contract->id}/notice", [
            'scheduled_move_out_on' => '2026-07-29',
        ]);

        $response->assertOk();
        $this->assertSame('notice_given', $response->json('data.status'));
        $this->assertSame('2026-07-15', $response->json('data.notice_given_on'));
        $this->assertSame('2026-07-29', $response->json('data.scheduled_move_out_on'));

        $occupancy = UnitOccupancy::query()->where('contract_id', $contract->id)->first();
        $this->assertSame('2026-07-29', $occupancy?->ended_on?->toDateString());

        $available = Unit::query()
            ->whereKey($this->unit->id)
            ->tap(fn ($q) => Availability::scopeAvailableBetween(
                $q,
                CarbonImmutable::parse('2026-07-29'),
                CarbonImmutable::parse('2026-08-15'),
            ))
            ->exists();

        $this->assertTrue($available);
    }

    public function test_withdrawal_reopens_occupancy(): void
    {
        $contract = $this->signContract('2026-06-01');
        $this->postJson("/api/contracts/{$contract->id}/notice", [
            'scheduled_move_out_on' => '2026-07-29',
        ])->assertOk();

        $response = $this->postJson("/api/contracts/{$contract->id}/notice-withdraw");
        $response->assertOk();
        $this->assertSame('active', $response->json('data.status'));
        $this->assertNull($response->json('data.notice_given_on'));
        $this->assertNull($response->json('data.scheduled_move_out_on'));

        $occupancy = UnitOccupancy::query()->where('contract_id', $contract->id)->first();
        $this->assertNull($occupancy?->ended_on);
    }

    public function test_withdrawal_blocked_by_reservation(): void
    {
        $contract = $this->signContract('2026-06-01');
        $this->postJson("/api/contracts/{$contract->id}/notice", [
            'scheduled_move_out_on' => '2026-07-29',
        ])->assertOk();

        $reservation = Reservation::query()->create([
            'unit_id' => $this->unit->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => ReservationStatus::Confirmed,
            'expires_at' => now()->addDays(7),
        ]);

        UnitHold::query()->create([
            'unit_id' => $this->unit->id,
            'hold_type' => HoldType::Reservation,
            'reservation_id' => $reservation->id,
            'starts_on' => '2026-07-29',
            'ends_on' => '2026-08-12',
            'released_at' => null,
            'reason' => null,
        ]);

        $response = $this->postJson("/api/contracts/{$contract->id}/notice-withdraw");
        $response->assertStatus(422);
        $this->assertStringContainsString((string) $reservation->id, $response->json('errors.status.0'));

        $contract->refresh();
        $this->assertSame(ContractStatus::NoticeGiven, $contract->status);
    }

    public function test_move_out_closes_items_and_occupancy(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');

        $response = $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ]);

        $response->assertOk();
        $this->assertSame('ended', $response->json('data.status'));
        $this->assertSame('vacated', $response->json('data.ended_reason'));
        $this->assertSame('2026-07-20', $response->json('data.move_out_on'));

        $this->assertTrue(
            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->whereNull('effective_to')
                ->doesntExist()
        );
        $effectiveTo = ContractItem::query()->where('contract_id', $contract->id)->value('effective_to');
        $this->assertSame(
            '2026-07-20',
            $effectiveTo instanceof \DateTimeInterface
                ? $effectiveTo->format('Y-m-d')
                : Carbon::parse((string) $effectiveTo)->toDateString()
        );

        $occupancy = UnitOccupancy::query()->where('contract_id', $contract->id)->first();
        $this->assertSame('2026-07-20', $occupancy?->ended_on?->toDateString());
        $this->assertSame('vacated', $occupancy?->ended_reason);
    }

    public function test_same_day_relet_after_move_out(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');
        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $available = Unit::query()
            ->whereKey($this->unit->id)
            ->tap(fn ($q) => Availability::scopeAvailableBetween(
                $q,
                CarbonImmutable::parse('2026-07-20'),
                CarbonImmutable::parse('2026-08-01'),
            ))
            ->exists();

        $this->assertTrue($available);
    }

    public function test_turnover_hold_created_when_configured(): void
    {
        Setting::setBilling(Setting::billing()->with(turnoverHoldDays: 3));

        $contract = $this->signContract('2026-06-01', deposit: '100.00');
        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $hold = UnitHold::query()
            ->where('unit_id', $this->unit->id)
            ->where('hold_type', HoldType::Maintenance)
            ->where('reason', 'post_move_out_turnover')
            ->first();

        $this->assertNotNull($hold);
        $this->assertSame('2026-07-20', $hold->starts_on->toDateString());
        $this->assertSame('2026-07-23', $hold->ends_on?->toDateString());
    }

    public function test_policy_none_credits_nothing(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00', settlement: MoveOutSettlement::None);
        $contract->forceFill(['billed_through' => '2026-08-01'])->save();
        $before = Charge::query()->where('contract_id', $contract->id)->count();

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $adjustments = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Adjustment)
            ->where('description', 'like', 'vacate.credit%')
            ->count();

        $this->assertSame(0, $adjustments);
        $this->assertSame(
            $before + 1, // refund only
            Charge::query()->where('contract_id', $contract->id)->count()
        );
    }

    public function test_policy_daily_credits_to_the_cent(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00', settlement: MoveOutSettlement::Daily);
        $contract->forceFill([
            'billed_through' => '2026-08-01',
            'notice_period_days' => 0,
        ])->save();

        // Seed a rent charge so tax snapshot can be found.
        Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => $contract->unitItem?->id,
            'charge_type' => ChargeType::Rent,
            'period_start' => '2026-07-01',
            'period_end' => '2026-08-01',
            'net_amount' => '310.00',
            'tax_rate_snapshot' => '21.00',
            'tax_amount' => '65.10',
            'amount' => '375.10',
            'currency' => 'EUR',
            'due_date' => '2026-07-01',
            'description' => 'Rent',
        ]);

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $credit = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Adjustment)
            ->where('description', 'like', 'vacate.credit%')
            ->first();

        $this->assertNotNull($credit);
        $days = BillingMath::daysBetween(
            CarbonImmutable::parse('2026-07-20'),
            CarbonImmutable::parse('2026-08-01'),
        );
        $daysInPeriod = BillingMath::daysBetween(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-08-01'),
        );
        $expectedNet = bcmul(BillingMath::prorate('310.00', $days, $daysInPeriod), '-1', 2);
        $this->assertSame($expectedNet, (string) $credit->net_amount);
        $this->assertSame('21.00', (string) $credit->tax_rate_snapshot);
    }

    public function test_under_billed_gap_charged_under_every_policy(): void
    {
        foreach ([MoveOutSettlement::None, MoveOutSettlement::Daily, MoveOutSettlement::NoticeBased] as $policy) {
            $unit = Unit::factory()->create([
                'site_id' => $this->site->id,
                'unit_class_id' => $this->unit->unit_class_id,
            ]);

            Setting::setBilling(Setting::billing()->with(
                defaultDepositAmount: '100.00',
                moveOutSettlement: $policy->value,
                turnoverHoldDays: 0,
            ));

            $response = $this->postJson('/api/contracts', [
                'contact_id' => Contact::factory()->create()->id,
                'start_date' => '2026-06-01',
                'move_in_date' => '2026-06-01',
                'deposit_amount' => '100.00',
                'items' => [
                    [
                        'item_type' => 'unit',
                        'item_id' => $unit->id,
                        'amount' => '310.00',
                    ],
                ],
            ])->assertCreated();

            $contract = Contract::query()->findOrFail($response->json('data.id'));
            $contract->forceFill([
                'billed_through' => '2026-07-01',
                'notice_period_days' => 0,
            ])->save();

            $this->postJson("/api/contracts/{$contract->id}/vacate", [
                'move_out_on' => '2026-07-20',
                'deposit' => ['outcome' => 'released'],
            ])->assertOk();

            $gap = Charge::query()
                ->where('contract_id', $contract->id)
                ->where('charge_type', ChargeType::Rent)
                ->where('description', 'vacate.gap')
                ->first();

            $this->assertNotNull($gap, "Expected gap charge for policy {$policy->value}");
            $this->assertSame('2026-07-01', $gap->period_start?->toDateString());
            $this->assertSame('2026-07-20', $gap->period_end?->toDateString());
        }
    }

    public function test_later_of_rule_for_early_leaver(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00', settlement: MoveOutSettlement::Daily);
        $contract->forceFill([
            'billed_through' => '2026-08-15',
            'notice_period_days' => 14,
        ])->save();

        $this->postJson("/api/contracts/{$contract->id}/notice", [
            'scheduled_move_out_on' => '2026-07-18',
        ])->assertOk();

        $preview = $this->postJson("/api/contracts/{$contract->id}/vacate-preview", [
            'move_out_on' => '2026-07-18',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        // notice 2026-07-15 + 14 = 2026-07-29 > move_out 2026-07-18
        $this->assertSame('2026-07-29', $preview->json('data.final_billing_date'));
        $this->assertSame('2026-07-29', $preview->json('data.notice_derived_date'));
    }

    public function test_deposit_release_writes_refund_row(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $refund = Charge::query()
            ->where('contract_id', $contract->id)
            ->where('charge_type', ChargeType::Refund)
            ->first();

        $this->assertNotNull($refund);
        $this->assertSame('-100.00', (string) $refund->net_amount);

        $settlement = DepositSettlement::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($settlement);
        $this->assertSame('pending', $settlement->payout_status->value);
        $this->assertSame('100.00', (string) $settlement->refunded_amount);
    }

    public function test_deductions_capped_at_deposit(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => [
                'outcome' => 'deducted',
                'deductions' => [
                    ['amount' => '150.00', 'reason' => 'Too much'],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_forfeit_requires_reason(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => [
                'outcome' => 'forfeited',
                'deductions' => [
                    ['amount' => '100.00', 'reason' => ''],
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_deductions_count_as_revenue_refund_does_not(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => [
                'outcome' => 'deducted',
                'deductions' => [
                    ['amount' => '40.00', 'reason' => 'Door damage'],
                ],
            ],
        ])->assertOk();

        $charges = Charge::query()->where('contract_id', $contract->id)->get();
        $revenue = RevenueByCurrency::group($charges);

        $this->assertArrayHasKey('EUR', $revenue);
        // First-period rent + deposit deduction adjustment; refund excluded
        $this->assertTrue(bccomp($revenue['EUR'], '0', 2) === 1);

        $refund = $charges->first(fn (Charge $c) => $c->charge_type === ChargeType::Refund);
        $this->assertNotNull($refund);
        $this->assertFalse($refund->charge_type->isRevenue());

        $deduction = $charges->first(
            fn (Charge $c) => $c->charge_type === ChargeType::Adjustment
                && str_contains((string) $c->description, 'deposit_deduction')
        );
        $this->assertNotNull($deduction);
        $this->assertTrue($deduction->charge_type->isRevenue());
    }

    public function test_preview_matches_commit(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00', settlement: MoveOutSettlement::Daily);
        $contract->forceFill([
            'billed_through' => '2026-08-01',
            'notice_period_days' => 0,
        ])->save();

        $body = [
            'move_out_on' => '2026-07-20',
            'deposit' => [
                'outcome' => 'deducted',
                'deductions' => [
                    ['amount' => '25.00', 'reason' => 'Cleaning'],
                ],
            ],
        ];

        $preview = $this->postJson("/api/contracts/{$contract->id}/vacate-preview", $body)
            ->assertOk()
            ->json('data');

        $this->postJson("/api/contracts/{$contract->id}/vacate", $body)->assertOk();

        $contract->refresh()->load('charges', 'depositSettlement.lines');

        $this->assertSame($preview['final_billing_date'], '2026-07-20');
        $this->assertSame($preview['deposit']['outcome'], $contract->depositSettlement?->outcome->value);
        $this->assertSame(
            $preview['deposit']['refunded_amount'],
            (string) $contract->depositSettlement?->refunded_amount
        );
        $this->assertSame($preview['payout_amount'], (string) $contract->depositSettlement?->refunded_amount);

        $vacateCharges = $contract->charges
            ->filter(fn (Charge $c) => str_starts_with((string) $c->description, 'vacate.'))
            ->values();

        $previewGross = '0.00';
        foreach ($preview['item_lines'] as $line) {
            $previewGross = bcadd($previewGross, (string) $line['gross'], 2);
        }
        foreach ($preview['deposit']['lines'] as $line) {
            $previewGross = bcadd($previewGross, (string) $line['gross'], 2);
        }

        $commitGross = '0.00';
        foreach ($vacateCharges as $charge) {
            $commitGross = bcadd($commitGross, (string) $charge->amount, 2);
        }

        $this->assertSame($previewGross, $commitGross);
        $this->assertSame($preview['resulting_balance'], $contract->balanceOwed());
    }

    public function test_double_vacate_rejected(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $chargeCount = Charge::query()->where('contract_id', $contract->id)->count();

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-21',
            'deposit' => ['outcome' => 'released'],
        ])->assertStatus(422);

        $this->assertSame($chargeCount, Charge::query()->where('contract_id', $contract->id)->count());
    }

    public function test_billed_through_untouched(): void
    {
        $contract = $this->signContract('2026-06-01', deposit: '100.00');
        $contract->forceFill(['billed_through' => '2026-08-01'])->save();

        $this->postJson("/api/contracts/{$contract->id}/vacate", [
            'move_out_on' => '2026-07-20',
            'deposit' => ['outcome' => 'released'],
        ])->assertOk();

        $contract->refresh();
        $this->assertSame('2026-08-01', $contract->billedThrough());
    }

    private function signContract(
        string $moveIn,
        string $deposit = '0.00',
        MoveOutSettlement $settlement = MoveOutSettlement::None,
    ): Contract {
        Setting::setBilling(Setting::billing()->with(
            defaultDepositAmount: $deposit,
            moveOutSettlement: $settlement->value,
            turnoverHoldDays: Setting::billing()->turnoverHoldDays,
        ));

        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/contracts', [
            'contact_id' => $contact->id,
            'start_date' => $moveIn,
            'move_in_date' => $moveIn,
            'deposit_amount' => $deposit,
            'items' => [
                [
                    'item_type' => 'unit',
                    'item_id' => $this->unit->id,
                    'amount' => '310.00',
                ],
            ],
        ]);

        $response->assertCreated();

        $contract = Contract::query()->findOrFail($response->json('data.id'));
        $this->assertSame($settlement, $contract->move_out_settlement);

        return $contract;
    }
}
