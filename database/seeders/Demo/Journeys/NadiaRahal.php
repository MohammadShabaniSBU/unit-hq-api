<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractItemChangeReason;
use App\Enums\DiscountKind;
use App\Models\ContractItem;
use App\Models\ContractNotice;
use App\Models\Discount;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Nadia Rahal — rate-change surfaces + 20% tracking discount (DISC-02).
 *
 * One historically applied rate change + one scheduled two months out.
 * Discount recompute is visible across the applied change.
 */
final class NadiaRahal extends Journey
{
    public static function handle(): string
    {
        return 'nadia';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 100;
        $appliedDay = $end - 40;
        $scheduledDay = $end - 5;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'nadia', 'Nadia', 'Rahal', [
                    'email' => 'nadia.rahal@demo.unit-hq.test',
                ]);
                JourneySupport::openDeal($world, 'nadia', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS5');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                $discountId = Discount::query()
                    ->where('kind', DiscountKind::Percent)
                    ->where('name', '20% off')
                    ->value('id');
                JourneySupport::walkInSign(
                    $world,
                    'nadia',
                    $unit,
                    $date,
                    discountId: $discountId !== null ? (int) $discountId : null,
                );
                JourneySupport::markSteadyPayer($world, 'nadia');
            },
            $appliedDay => static function (DemoWorld $world) use ($appliedDay): void {
                $contract = JourneySupport::contract($world, 'nadia')->fresh(['items.price']);
                $item = $contract->items()->where('item_type', 'unit')->whereNull('effective_to')->firstOrFail();
                // new_amount is list — bump base_rate (not the discounted contract amount).
                $list = BillingMath::round2((string) ($item->base_rate ?? $item->price->amount));
                $new = BillingMath::round2(bcadd($list, '15.00', 2));
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($appliedDay)
                    ->toDateString();
                JourneySupport::scheduleRateChange($world, 'nadia', $new, $date);
            },
            $scheduledDay => static function (DemoWorld $world) use ($end): void {
                $contract = JourneySupport::contract($world, 'nadia')->fresh(['items.price']);
                $item = $contract->items()->where('item_type', 'unit')->whereNull('effective_to')->firstOrFail();
                $list = BillingMath::round2((string) ($item->base_rate ?? $item->price->amount));
                $new = BillingMath::round2(bcadd($list, '20.00', 2));
                $effective = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($end + 60)
                    ->toDateString();
                JourneySupport::scheduleRateChange($world, 'nadia', $new, $effective);
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'nadia');

        $rateChanges = ContractItem::query()
            ->where('contract_id', $contract->id)
            ->where('change_reason', ContractItemChangeReason::RateChange)
            ->count();
        Assert::assertGreaterThanOrEqual(2, $rateChanges);

        Assert::assertTrue(
            ContractNotice::query()->where('contract_id', $contract->id)->exists(),
            'Nadia should have rate-change notices',
        );

        $future = ContractItem::query()
            ->where('contract_id', $contract->id)
            ->where('change_reason', ContractItemChangeReason::RateChange)
            ->where('effective_from', '>', CastExecutor::SIM_END)
            ->exists();
        Assert::assertTrue($future, 'Nadia should have a future-dated rate change');

        $open = ContractItem::query()
            ->with(['price', 'discount'])
            ->where('contract_id', $contract->id)
            ->where('item_type', 'unit')
            ->whereNull('effective_to')
            ->firstOrFail();
        Assert::assertNotNull($open->discount_id, 'Nadia should still carry the 20% discount');
        Assert::assertSame('20% off', $open->discount?->name);

        $list = BillingMath::round2((string) $open->base_rate);
        $expected = BillingMath::round2(bcmul($list, '0.80', 8));
        Assert::assertSame(
            $expected,
            BillingMath::round2((string) $open->price->amount),
            'Nadia open version should be 80% of current list',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
