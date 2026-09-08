<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Víctor Palencia — cancelled never-moved-in + lost deal.
 */
final class ViktorPalenik extends Journey
{
    public static function handle(): string
    {
        return 'viktor';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 25;
        $cancelDay = $end - 20;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay, $end): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'viktor', 'Víctor', 'Palencia', [
                    'email' => 'victor.palencia@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'viktor', $site, DealStatus::OfferSent);
                $unit = JourneySupport::vacantUnit($site, 'SS4');
                $start = CastExecutor::civilDate($startDay);
                $moveIn = CastExecutor::civilDate($end + 20);
                JourneySupport::walkInSign(
                    $world,
                    'viktor',
                    $unit,
                    $start,
                    moveInDate: $moveIn,
                    mode: 'remote',
                );
                JourneySupport::sendEnvelope($world, 'viktor');
            },
            $cancelDay => static function (DemoWorld $world): void {
                JourneySupport::cancelContract($world, 'viktor');
                /** @var \App\Models\Deal $deal */
                $deal = $world->get('viktor.deal');
                $deal->forceFill(['status' => DealStatus::ClosedLost])->save();
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'viktor')->fresh();
        Assert::assertSame(ContractStatus::Cancelled, $contract->status);

        /** @var \App\Models\Deal $deal */
        $deal = $world->get('viktor.deal');
        Assert::assertSame(DealStatus::ClosedLost, $deal->fresh()->status);
    }

}
