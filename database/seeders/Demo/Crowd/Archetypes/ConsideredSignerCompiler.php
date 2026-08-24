<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use App\Models\EsignEnvelope;
use Database\Seeders\Demo\Comms\ContentLibrary;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Longer funnel: viewed offer → accept (reservation) → convert. Some remote signatures.
 */
final class ConsideredSignerCompiler
{
    /**
     * @return array<int, callable(DemoWorld): void>
     */
    public static function compile(
        string $handle,
        DemoRng $rng,
        bool $withScheduledRate = false,
        bool $withDiscount = false,
    ): array {
        $enrol = CrowdSupport::enrolDay($rng, minTenureDays: 60, band: 'early');
        $viewDay = $enrol + $rng->int(3, 10);
        $reply1 = $viewDay + 1;
        $reply2 = $viewDay + 3;
        $signDay = max($reply2 + 1, $viewDay + $rng->int(2, 12));
        $remote = $rng->bool(0.45);
        $negotiator = $rng->bool(0.55);
        $withInsurance = $rng->bool(0.5);
        $library = new ContentLibrary($rng);
        $discountPick = $withDiscount ? CrowdSupport::pickDiscount($rng) : null;

        $script = [
            $enrol => static function (DemoWorld $world) use ($handle, $rng, $discountPick): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                $class = $unit->unitClass()->first();
                JourneySupport::openDeal($world, $handle, $site, DealStatus::OfferSent);
                JourneySupport::createOffer(
                    $world,
                    $handle,
                    $site,
                    $class?->code ?? $rng->pick(CrowdSupport::UNIT_CLASSES),
                    'sent',
                    discountId: $discountPick['discount_id'] ?? null,
                    unit: $unit,
                );
            },
            $viewDay => static function (DemoWorld $world) use ($handle): void {
                JourneySupport::markOfferViewed($world, $handle);
                if ($world->has("{$handle}.deal")) {
                    $deal = $world->get("{$handle}.deal");
                    $deal->forceFill(['status' => DealStatus::OfferViewed])->save();
                }
            },
        ];

        if ($negotiator) {
            $script[$reply1] = static function (DemoWorld $world) use ($handle, $library): void {
                JourneySupport::inboundEmail($world, $handle, $library->emailBody('offer_reply'));
            };
            $script[$reply2] = static function (DemoWorld $world) use ($handle, $library): void {
                JourneySupport::inboundEmail($world, $handle, $library->emailBody('offer_reply'));
            };
        }

        if ($remote) {
            $script[$signDay] = static function (DemoWorld $world) use ($handle, $signDay, $discountPick, $withInsurance): void {
                JourneySupport::acceptOffer($world, $handle);
                JourneySupport::convertReservation(
                    $world,
                    $handle,
                    CrowdSupport::dateOn($signDay),
                    mode: 'remote',
                    withInsurance: $withInsurance,
                    commitmentWeeks: $discountPick['commitment_weeks'] ?? null,
                );
                JourneySupport::sendEnvelope($world, $handle);
            };
            $viewEnv = $signDay + 1;
            $signed = $signDay + $rng->int(2, 5);
            $script[$viewEnv] = static function (DemoWorld $world) use ($handle): void {
                /** @var EsignEnvelope $envelope */
                $envelope = $world->get("{$handle}.envelope");
                $world->esign()->viewed($envelope->fresh() ?? $envelope);
            };
            $script[$signed] = static function (DemoWorld $world) use ($handle): void {
                /** @var EsignEnvelope $envelope */
                $envelope = $world->get("{$handle}.envelope");
                $world->esign()->signed($envelope->fresh() ?? $envelope);
                JourneySupport::markSteadyPayer($world, $handle);
            };
        } else {
            $script[$signDay] = static function (DemoWorld $world) use ($handle, $signDay, $discountPick, $withInsurance): void {
                JourneySupport::acceptOffer($world, $handle);
                JourneySupport::convertReservation(
                    $world,
                    $handle,
                    CrowdSupport::dateOn($signDay),
                    withInsurance: $withInsurance,
                    commitmentWeeks: $discountPick['commitment_weeks'] ?? null,
                );
                JourneySupport::markSteadyPayer($world, $handle);
            };
        }

        if ($withScheduledRate) {
            $effective = CrowdSupport::simSpanDays() + $rng->int(30, 70);
            $scheduleDay = max($signDay + 14, CrowdSupport::simSpanDays() - 5);
            $script[$scheduleDay] = static function (DemoWorld $world) use ($handle, $rng, $effective): void {
                if (! $world->has("{$handle}.contract")) {
                    return;
                }
                $contract = JourneySupport::contract($world, $handle);
                $item = $contract->items()->where('item_type', 'unit')->whereNull('effective_to')->with('price')->first();
                if ($item?->price === null) {
                    return;
                }
                $list = (string) ($item->base_rate ?? $item->price->amount);
                $newAmount = bcadd($list, (string) $rng->int(8, 30), 2);
                JourneySupport::scheduleRateChange(
                    $world,
                    $handle,
                    $newAmount,
                    CrowdSupport::simStart()->addDays($effective)->toDateString(),
                    acknowledgeShortNotice: true,
                );
            };
        }

        return $script;
    }
}
