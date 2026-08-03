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
 * Amara Okafor — remote signer.
 *
 * Offer → awaiting envelope → viewed → signed via e-sign injector → active.
 * End state: active contract with signed PDF + certificate on file.
 */
final class AmaraOkafor extends Journey
{
    public static function handle(): string
    {
        return 'amara';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 40;
        $viewDay = $end - 38;
        $signDay = $end - 36;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'amara', 'Amara', 'Okafor', [
                    'email' => 'amara.okafor@demo.unit-hq.test',
                ]);
                JourneySupport::openDeal($world, 'amara', $site, DealStatus::OfferSent);
                JourneySupport::createOffer($world, 'amara', $site, 'SS5', 'sent');

                $unit = JourneySupport::vacantUnit($site, 'SS5');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'amara', $unit, $date, mode: 'remote');
                JourneySupport::sendEnvelope($world, 'amara');
            },
            $viewDay => static function (DemoWorld $world): void {
                /** @var EsignEnvelope $envelope */
                $envelope = $world->get('amara.envelope');
                $world->esign()->viewed($envelope->fresh() ?? $envelope);
            },
            $signDay => static function (DemoWorld $world): void {
                /** @var EsignEnvelope $envelope */
                $envelope = $world->get('amara.envelope');
                $world->esign()->signed($envelope->fresh() ?? $envelope);
                JourneySupport::markSteadyPayer($world, 'amara');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'amara')->fresh();
        Assert::assertContains(
            $contract->status,
            [ContractStatus::Active, ContractStatus::Pending],
            'Amara should be pending or active after remote sign',
        );

        $envelope = EsignEnvelope::query()
            ->where('contract_id', $contract->id)
            ->latest('id')
            ->first();
        Assert::assertNotNull($envelope);
        Assert::assertSame(EsignEnvelopeStatus::Signed, $envelope->status);
        Assert::assertNotNull($envelope->signed_pdf_path);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
