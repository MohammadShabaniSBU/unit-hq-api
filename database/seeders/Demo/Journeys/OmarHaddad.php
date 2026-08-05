<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Omar Haddad — pending activation.
 *
 * Walk-in signed with move-in 10 days after seed-end → Pending tab.
 */
final class OmarHaddad extends Journey
{
    public static function handle(): string
    {
        return 'omar';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $signDay = $end - 3;

        return [
            $signDay => static function (DemoWorld $world) use ($signDay, $end): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'omar', 'Omar', 'Haddad', [
                    'email' => 'omar.haddad@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'omar', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS4');
                $start = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($signDay)
                    ->toDateString();
                $moveIn = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($end + 10)
                    ->toDateString();
                JourneySupport::walkInSign(
                    $world,
                    'omar',
                    $unit,
                    $start,
                    moveInDate: $moveIn,
                );
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'omar')->fresh();
        Assert::assertSame(ContractStatus::Pending, $contract->status);
        Assert::assertNotNull($contract->signed_at);

        $moveIn = CarbonImmutable::parse($contract->move_in_date)->startOfDay();
        $end = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay();
        Assert::assertTrue($moveIn->greaterThan($end));
        Assert::assertSame(10, (int) $end->diffInDays($moveIn));
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
