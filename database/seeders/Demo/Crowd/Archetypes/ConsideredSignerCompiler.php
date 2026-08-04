<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd\Archetypes;

use App\Enums\DealStatus;
use App\Models\EsignEnvelope;
use App\Models\Site;
use Database\Seeders\Demo\Comms\ContentLibrary;
use Database\Seeders\Demo\Crowd\CrowdSupport;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Longer funnel, viewed offers, some remote signatures.
 * Negotiator slice: two inbound replies to the offer email before accepting.
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
        $enrol = CrowdSupport::enrolDay($rng, minTenureDays: 60);
        $viewDay = $enrol + $rng->int(3, 10);
        $reply1 = $viewDay + 1;
        $reply2 = $viewDay + 3;
        $signDay = max($reply2 + 1, $viewDay + $rng->int(2, 12));
        $remote = $rng->bool(0.45);
        $negotiator = $rng->bool(0.55);
        $library = new ContentLibrary($rng);
        $discountPick = $withDiscount ? CrowdSupport::pickDiscount($rng) : null;

        $script = [
            $enrol => static function (DemoWorld $world) use ($handle, $rng): void {
                CrowdSupport::createCrowdContact($world, $handle, $rng);
                $site = CrowdSupport::pickSite($world, $rng);
                JourneySupport::openDeal($world, $handle, $site, DealStatus::OfferSent);
                JourneySupport::createOffer(
                    $world,
                    $handle,
                    $site,
                    $rng->pick(CrowdSupport::UNIT_CLASSES),
                    'sent',
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
            $script[$signDay] = static function (DemoWorld $world) use ($handle, $rng, $signDay, $discountPick): void {
                $deal = $world->get("{$handle}.deal");
                $site = Site::query()->findOrFail((int) $deal->site_id);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                JourneySupport::walkInSign(
                    $world,
                    $handle,
                    $unit,
                    CrowdSupport::dateOn($signDay),
                    mode: 'remote',
                    discountId: $discountPick['discount_id'] ?? null,
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
            $script[$signDay] = static function (DemoWorld $world) use ($handle, $rng, $signDay, $discountPick): void {
                $deal = $world->get("{$handle}.deal");
                $site = Site::query()->findOrFail((int) $deal->site_id);
                $unit = CrowdSupport::vacantUnit($site, $rng);
                JourneySupport::walkInSign(
                    $world,
                    $handle,
                    $unit,
                    CrowdSupport::dateOn($signDay),
                    discountId: $discountPick['discount_id'] ?? null,
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
