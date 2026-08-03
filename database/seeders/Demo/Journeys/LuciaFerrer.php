<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\AccessEventType;
use App\Enums\ContractStatus;
use App\Enums\HoldType;
use App\Models\AccessEvent;
use App\Models\Delinquency;
use App\Models\UnitHold;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Delinquency\Overlock;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Lucía Ferrer — full delinquency ladder.
 *
 * Steady Madrid tenant who stops paying near seed-end. The ES policy (fee d5,
 * notice+suspend d8, overlock d12) runs via delinquency:run. After overlock we
 * inject a denied door event. End state: open case in the 15–30 bucket,
 * overlocked, timeline complete, still owing.
 */
final class LuciaFerrer extends Journey
{
    public static function handle(): string
    {
        return 'lucia';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $missFrom = $end - 28;
        $callDay = $end - 20;
        $doorDay = $end - 5;

        return [
            0 => static function (DemoWorld $world): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'lucia', 'Lucía', 'Ferrer', [
                    'email' => 'lucia.ferrer@demo.unit-hq.test',
                ]);
                JourneySupport::openDeal($world, 'lucia', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS3');
                JourneySupport::walkInSign(
                    $world,
                    'lucia',
                    $unit,
                    CarbonImmutable::parse(CastExecutor::SIM_START)->toDateString(),
                );
                JourneySupport::markSteadyPayer($world, 'lucia');
            },
            $missFrom => static function (DemoWorld $world): void {
                JourneySupport::startMissingPayments($world, 'lucia');
            },
            $callDay => static function (DemoWorld $world): void {
                JourneySupport::recordCallWrapup(
                    $world,
                    'lucia',
                    'payment_promised',
                    'Promised payment after overlock warning',
                    direction: 'outbound',
                );
            },
            $doorDay => static function (DemoWorld $world): void {
                // Denied attempt after the ladder has had time to overlock.
                JourneySupport::doorDenied($world, 'lucia');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'lucia')->fresh(['charges.allocations']);
        Assert::assertSame(ContractStatus::Active, $contract->status);

        $case = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->first();
        Assert::assertNotNull($case, 'Lucía should have an open delinquency case');

        $days = DelinquencyState::daysOverdue($contract);
        Assert::assertGreaterThanOrEqual(15, $days, "Lucía days overdue expected ≥15, got {$days}");
        Assert::assertLessThanOrEqual(45, $days, "Lucía days overdue expected ≤45, got {$days}");

        Assert::assertTrue(
            Overlock::liveHolds($case)->isNotEmpty()
                || UnitHold::query()
                    ->where('hold_type', HoldType::Overlock)
                    ->whereNull('released_at')
                    ->exists(),
            'Lucía should be overlocked',
        );

        Assert::assertTrue(
            AccessEvent::query()
                ->where('event_type', AccessEventType::Denied)
                ->where(static function ($q) use ($world): void {
                    $q->where('contact_id', $world->contact('lucia.contact')->id)
                        ->orWhereNull('contact_id');
                })
                ->exists(),
            'Lucía should have a denied door event',
        );

        Assert::assertTrue(
            bccomp(DelinquencyState::netOverdueAmount($contract), '0', 2) > 0,
            'Lucía should still owe',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
