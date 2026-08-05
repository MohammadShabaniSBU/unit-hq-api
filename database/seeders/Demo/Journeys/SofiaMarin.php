<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Models\EsignEnvelope;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Sofía Marín — awaiting-expiring.
 *
 * Envelope sent 12 days before seed-end with a 14-day expiry → amber ≤3d row.
 */
final class SofiaMarin extends Journey
{
    public static function handle(): string
    {
        return 'sofia';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $sendDay = $end - 12;

        return [
            $sendDay => static function (DemoWorld $world) use ($sendDay, $end): void {
                $site = $world->site('barcelona');
                JourneySupport::createContact($world, 'sofia', 'Sofía', 'Marín', [
                    'email' => 'sofia.marin@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'sofia', $site, DealStatus::OfferSent);
                $unit = JourneySupport::vacantUnit($site, 'SS4');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($sendDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'sofia', $unit, $date, mode: 'remote');

                $expiresAt = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($end + 2)
                    ->endOfDay();
                JourneySupport::sendEnvelope($world, 'sofia', $expiresAt);
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'sofia')->fresh();
        Assert::assertSame(ContractStatus::AwaitingSignature, $contract->status);

        $envelope = EsignEnvelope::query()
            ->where('contract_id', $contract->id)
            ->latest('id')
            ->first();
        Assert::assertNotNull($envelope);
        Assert::assertNotNull($envelope->expires_at);

        $seedEnd = CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay();
        $daysLeft = (int) $seedEnd->diffInDays(
            CarbonImmutable::parse($envelope->expires_at)->startOfDay(),
            false,
        );
        Assert::assertLessThanOrEqual(3, $daysLeft);
        Assert::assertGreaterThanOrEqual(0, $daysLeft);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
