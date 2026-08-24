<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Inés Valdés — notice-given, mid-notice.
 *
 * Move-out scheduled for next week at seed-end. Notice tab row + stop-line.
 */
final class IngridWeiss extends Journey
{
    public static function handle(): string
    {
        return 'ingrid';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 90;
        $noticeDay = $end - 5;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'ingrid', 'Inés', 'Valdés', [
                    'email' => 'ines.valdes@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'ingrid', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS5');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'ingrid', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'ingrid');
            },
            $noticeDay => static function (DemoWorld $world) use ($end): void {
                $moveOut = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($end + 7)
                    ->toDateString();
                JourneySupport::giveNotice($world, 'ingrid', $moveOut);
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'ingrid')->fresh();
        Assert::assertSame(ContractStatus::NoticeGiven, $contract->status);
        Assert::assertNotNull($contract->notice_given_on);
        Assert::assertNotNull($contract->scheduled_move_out_on);

        $seedEnd = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay();
        $daysUntil = (int) $seedEnd->diffInDays(
            CarbonImmutable::parse($contract->scheduled_move_out_on)->startOfDay(),
            false,
        );
        Assert::assertGreaterThanOrEqual(5, $daysUntil);
        Assert::assertLessThanOrEqual(14, $daysUntil);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
