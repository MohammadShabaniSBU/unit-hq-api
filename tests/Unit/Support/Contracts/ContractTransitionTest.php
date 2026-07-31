<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Contracts;

use App\Enums\ContractStatus;
use App\Enums\LogChannel;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitOccupancy;
use App\Support\Contracts\ContractTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContractTransitionTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        Employee::factory()->manager()->create();
        $country = Country::factory()->create(['code' => 'ES']);
        $site = Site::factory()->create(['country_id' => $country->id]);
        $unitClass = UnitClass::factory()->create();
        $this->unit = Unit::factory()->create([
            'site_id' => $site->id,
            'unit_class_id' => $unitClass->id,
        ]);
    }

    /**
     * @return array<string, array{0: ContractStatus, 1: ContractStatus}>
     */
    public static function permittedTransitionsProvider(): array
    {
        return [
            'pending→active' => [ContractStatus::Pending, ContractStatus::Active],
            'pending→cancelled' => [ContractStatus::Pending, ContractStatus::Cancelled],
            'active→notice_given' => [ContractStatus::Active, ContractStatus::NoticeGiven],
            'active→ended' => [ContractStatus::Active, ContractStatus::Ended],
            'active→cancelled' => [ContractStatus::Active, ContractStatus::Cancelled],
            'notice_given→active' => [ContractStatus::NoticeGiven, ContractStatus::Active],
            'notice_given→ended' => [ContractStatus::NoticeGiven, ContractStatus::Ended],
        ];
    }

    #[DataProvider('permittedTransitionsProvider')]
    public function test_permitted_transitions_succeed(ContractStatus $from, ContractStatus $to): void
    {
        $contract = $this->makeContract($from);

        ContractTransition::apply($contract, $to);

        $this->assertSame($to, $contract->fresh()->status);
    }

    /**
     * @return array<string, array{0: ContractStatus, 1: ContractStatus}>
     */
    public static function forbiddenTransitionsProvider(): array
    {
        return [
            'pending→notice_given' => [ContractStatus::Pending, ContractStatus::NoticeGiven],
            'pending→ended' => [ContractStatus::Pending, ContractStatus::Ended],
            'active→pending' => [ContractStatus::Active, ContractStatus::Pending],
            'notice_given→cancelled' => [ContractStatus::NoticeGiven, ContractStatus::Cancelled],
            'notice_given→pending' => [ContractStatus::NoticeGiven, ContractStatus::Pending],
            'ended→active' => [ContractStatus::Ended, ContractStatus::Active],
            'cancelled→active' => [ContractStatus::Cancelled, ContractStatus::Active],
        ];
    }

    #[DataProvider('forbiddenTransitionsProvider')]
    public function test_forbidden_transitions_rejected(ContractStatus $from, ContractStatus $to): void
    {
        $contract = $this->makeContract($from);

        try {
            ContractTransition::assert($contract, $to);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame($from, $contract->fresh()->status);
    }

    public function test_terminal_states_reject_everything(): void
    {
        foreach ([ContractStatus::Ended, ContractStatus::Cancelled] as $terminal) {
            $contract = $this->makeContract($terminal);

            foreach (ContractStatus::cases() as $target) {
                if ($target === $terminal) {
                    continue;
                }

                try {
                    ContractTransition::assert($contract, $target);
                    $this->fail("Expected rejection for {$terminal->value} → {$target->value}");
                } catch (ValidationException) {
                    // expected
                }
            }

            $this->assertSame([], ContractTransition::allowed($contract));
        }
    }

    public function test_cancel_blocked_when_payments_exist(): void
    {
        $contract = $this->makeContract(ContractStatus::Active);
        Payment::factory()->create([
            'contract_id' => $contract->id,
            'amount' => '50.00',
            'currency' => 'EUR',
        ]);

        try {
            ContractTransition::assert($contract, ContractStatus::Cancelled);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'payments',
                strtolower(implode(' ', $e->errors()['status'] ?? [])),
            );
        }

        $this->assertSame(ContractStatus::Active, $contract->fresh()->status);
        $this->assertNotContains(
            ContractStatus::Cancelled->value,
            ContractTransition::allowed($contract),
        );
    }

    public function test_notice_withdrawal_reopens_occupancy(): void
    {
        $contract = $this->makeContract(ContractStatus::NoticeGiven, [
            'notice_given_on' => '2026-07-01',
            'scheduled_move_out_on' => '2026-07-15',
        ]);

        UnitOccupancy::query()->create([
            'unit_id' => $this->unit->id,
            'contract_id' => $contract->id,
            'started_on' => '2026-01-01',
            'ended_on' => '2026-07-15',
        ]);

        ContractTransition::apply($contract, ContractStatus::Active);

        $contract->refresh();
        $this->assertSame(ContractStatus::Active, $contract->status);
        $this->assertNull($contract->notice_given_on);
        $this->assertNull($contract->scheduled_move_out_on);
        $this->assertSame(14, $contract->notice_period_days);

        $occupancy = UnitOccupancy::query()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($occupancy);
        $this->assertNull($occupancy->ended_on);
    }

    public function test_allowed_matches_assert(): void
    {
        foreach (ContractStatus::cases() as $from) {
            $contract = $this->makeContract($from);
            $allowed = ContractTransition::allowed($contract);

            foreach (ContractStatus::cases() as $to) {
                $shouldPass = in_array($to->value, $allowed, true);

                if ($shouldPass) {
                    ContractTransition::assert($contract, $to);
                    $this->addToAssertionCount(1);
                } else {
                    try {
                        ContractTransition::assert($contract, $to);
                        $this->fail("allowed() omitted {$to->value} but assert accepted it from {$from->value}");
                    } catch (ValidationException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
        }

        $withPayment = $this->makeContract(ContractStatus::Active);
        Payment::factory()->create([
            'contract_id' => $withPayment->id,
            'amount' => '10.00',
            'currency' => 'EUR',
        ]);
        $allowed = ContractTransition::allowed($withPayment);
        $this->assertNotContains(ContractStatus::Cancelled->value, $allowed);

        try {
            ContractTransition::assert($withPayment, ContractStatus::Cancelled);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_can_transfer_only_when_in_force(): void
    {
        $this->assertTrue(ContractTransition::canTransfer($this->makeContract(ContractStatus::Active)));
        $this->assertTrue(ContractTransition::canTransfer($this->makeContract(ContractStatus::NoticeGiven)));
        $this->assertFalse(ContractTransition::canTransfer($this->makeContract(ContractStatus::Pending)));
        $this->assertFalse(ContractTransition::canTransfer($this->makeContract(ContractStatus::Ended)));
        $this->assertFalse(ContractTransition::canTransfer($this->makeContract(ContractStatus::Cancelled)));

        ContractTransition::assertTransferable($this->makeContract(ContractStatus::Active));

        try {
            ContractTransition::assertTransferable($this->makeContract(ContractStatus::Ended));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_status_change_logs_core_activity(): void
    {
        $contract = $this->makeContract(ContractStatus::Active);

        DB::transaction(function () use ($contract): void {
            ContractTransition::apply($contract, ContractStatus::NoticeGiven);
        });

        $activity = Activity::query()
            ->where('log_name', LogChannel::Core->value)
            ->where('description', 'contract.status_changed')
            ->where('subject_id', $contract->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('active', $activity->properties->get('from'));
        $this->assertSame('notice_given', $activity->properties->get('to'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContract(ContractStatus $status, array $overrides = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'contact_id' => Contact::factory(),
            'status' => $status,
            'notice_period_days' => 14,
        ], $overrides));
    }
}
