<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\EsignEnvelopeStatus;
use App\Models\EsignEnvelope;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Javier Peña — awaiting-declined.
 *
 * Remote envelope declined with a reason. End state: awaiting signature with
 * declined attention chip.
 */
final class JeanLucPerrin extends Journey
{
    public static function handle(): string
    {
        return 'jean_luc';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 20;
        $declineDay = $end - 15;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'jean_luc', 'Javier', 'Peña', [
                    'email' => 'javier.pena@demo.keevaris.test',
                    'billing_country_code' => 'ES',
                    'billing_city' => 'Madrid',
                    'billing_postal_code' => '28001',
                ]);
                JourneySupport::openDeal($world, 'jean_luc', $site, DealStatus::OfferSent);
                $unit = JourneySupport::vacantUnit($site, 'SS4');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'jean_luc', $unit, $date, mode: 'remote');
                JourneySupport::sendEnvelope($world, 'jean_luc');
            },
            $declineDay => static function (DemoWorld $world): void {
                /** @var EsignEnvelope $envelope */
                $envelope = $world->get('jean_luc.envelope');
                $world->esign()->declined(
                    $envelope->fresh() ?? $envelope,
                    'Terms not acceptable — looking elsewhere',
                );
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'jean_luc')->fresh();
        Assert::assertSame(ContractStatus::AwaitingSignature, $contract->status);

        $envelope = EsignEnvelope::query()
            ->where('contract_id', $contract->id)
            ->latest('id')
            ->first();
        Assert::assertNotNull($envelope);
        Assert::assertSame(EsignEnvelopeStatus::Declined, $envelope->status);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
