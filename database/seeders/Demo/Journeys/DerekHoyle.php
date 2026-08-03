<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ChargeType;
use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Models\Charge;
use App\Models\Delinquency;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Derek Hoyle — non-payment vacate.
 *
 * Deep delinquency into the 60+ bucket, write-off cure, then ended involuntary.
 */
final class DerekHoyle extends Journey
{
    public static function handle(): string
    {
        return 'derek';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 120;
        $missDay = $end - 75;
        $callDay = $end - 40;
        $writeOffDay = $end - 8;
        $vacateDay = $end - 7;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('london');
                JourneySupport::createContact($world, 'derek', 'Derek', 'Hoyle', [
                    'email' => 'derek.hoyle@demo.unit-hq.test',
                    'billing_country_code' => 'GB',
                    'billing_city' => 'London',
                    'billing_postal_code' => 'E1 1AA',
                ]);
                JourneySupport::openDeal($world, 'derek', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS3');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'derek', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'derek');
            },
            $missDay => static function (DemoWorld $world): void {
                JourneySupport::startMissingPayments($world, 'derek');
            },
            $callDay => static function (DemoWorld $world): void {
                JourneySupport::recordCallWrapup(
                    $world,
                    'derek',
                    'payment_promised',
                    'Promised to catch up before vacate',
                    direction: 'outbound',
                );
            },
            $writeOffDay => static function (DemoWorld $world): void {
                JourneySupport::writeOff($world, 'derek', 'Non-payment — demo write-off');
            },
            $vacateDay => static function (DemoWorld $world) use ($vacateDay): void {
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($vacateDay)
                    ->toDateString();
                JourneySupport::vacate(
                    $world,
                    'derek',
                    $date,
                    endedReason: ContractEndedReason::NonPayment,
                );
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'derek')->fresh();
        Assert::assertSame(ContractStatus::Ended, $contract->status);
        Assert::assertSame(ContractEndedReason::NonPayment, $contract->ended_reason);

        Assert::assertTrue(
            Charge::query()
                ->where('contract_id', $contract->id)
                ->where('charge_type', ChargeType::WriteOff)
                ->exists(),
            'Derek should have a write-off charge',
        );

        Assert::assertTrue(
            Delinquency::query()
                ->where('contract_id', $contract->id)
                ->whereNotNull('cured_on')
                ->exists(),
            'Derek case should be cured via write-off',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
