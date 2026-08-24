<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Enums\DealStatus;
use App\Models\AutomationRun;
use App\Models\Offer;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Gracia Lin — funnel mid-stage.
 *
 * Deal in negotiation, offer viewed not accepted, lead-chase step 3 of 4
 * (timed so daily resume advances through the first three actions).
 */
final class GraceLin extends Journey
{
    public static function handle(): string
    {
        return 'grace';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        // D0 email, D1 task, D3 SMS → waiting on D7 email by seed-end.
        $enrolDay = $end - 7;

        return [
            $enrolDay => static function (DemoWorld $world): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'grace', 'Gracia', 'Lin', [
                    'email' => 'gracia.lin@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'grace', $site, DealStatus::Negotiating);
                JourneySupport::createOffer($world, 'grace', $site, 'SS6', 'sent');
                JourneySupport::markOfferViewed($world, 'grace');
                JourneySupport::enrolLeadChase($world, 'grace');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        /** @var \App\Models\Deal $deal */
        $deal = $world->get('grace.deal');
        Assert::assertSame(DealStatus::Negotiating, $deal->fresh()->status);

        /** @var Offer $offer */
        $offer = $world->get('grace.offer');
        Assert::assertSame('viewed', $offer->fresh()->status);
        Assert::assertNull($offer->fresh()->accepted_at);

        /** @var AutomationRun $run */
        $run = $world->get('grace.lead_chase_run');
        $run = $run->fresh() ?? $run;
        Assert::assertContains(
            $run->status,
            [AutomationRunStatus::Waiting, AutomationRunStatus::Running],
            'Grace lead-chase should still be in flight',
        );
        Assert::assertGreaterThanOrEqual(
            3,
            $run->steps()->where('status', AutomationRunStepStatus::Succeeded)->count(),
            'Grace should have completed the first three lead-chase actions',
        );
    }

    public static function maxDay(): int
    {
        // Keep the mini-clock running through seed-end so resume-waiting advances the chase.
        return self::endOffset();
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
