<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Models\CallWrapup;
use App\Models\Delinquency;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Tomás Blanco — promise-keeper.
 *
 * Misses a cycle, staff call and wrap up as payment_promised, then he pays
 * within four days. End state: cured history + wrap-up feeding promise-kept rate.
 */
final class TomBradley extends Journey
{
    public static function handle(): string
    {
        return 'tom';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 90;
        $missDay = $end - 25;
        $callDay = $end - 14;
        $payDay = $end - 10;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'tom', 'Tomás', 'Blanco', [
                    'email' => 'tomas.blanco@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'tom', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS2');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'tom', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'tom');
            },
            $missDay => static function (DemoWorld $world): void {
                JourneySupport::startMissingPayments($world, 'tom');
            },
            $callDay => static function (DemoWorld $world): void {
                JourneySupport::recordCallWrapup(
                    $world,
                    'tom',
                    'payment_promised',
                    'Promised to pay by end of week',
                );
            },
            $payDay => static function (DemoWorld $world): void {
                JourneySupport::payOpenBalance($world, 'tom');
                JourneySupport::markSteadyPayer($world, 'tom');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        Assert::assertTrue(
            CallWrapup::query()
                ->where('disposition', 'payment_promised')
                ->whereHas('message.thread', static fn ($q) => $q->where(
                    'contact_id',
                    $world->contact('tom.contact')->id,
                ))
                ->exists(),
            'Tom should have a payment_promised wrap-up',
        );

        $contract = JourneySupport::contract($world, 'tom');
        Assert::assertTrue(
            Delinquency::query()
                ->where('contract_id', $contract->id)
                ->whereNotNull('cured_on')
                ->exists(),
            'Tom should have cured delinquency history',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
