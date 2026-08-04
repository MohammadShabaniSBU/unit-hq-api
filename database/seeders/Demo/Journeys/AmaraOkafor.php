<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use App\Enums\DealStatus;
use App\Enums\DiscountKind;
use App\Models\ContractItem;
use App\Models\Discount;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Amara Okafor — long-stay free-time promo (DISC-02).
 *
 * Walk-in signed ~3 weeks before seed-end; still inside the €0 free window
 * (rent-roll visible). Remote-signer coverage remains on Jean-Luc / Sofía.
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
        $signDay = $end - 21;

        return [
            $signDay => static function (DemoWorld $world) use ($signDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'amara', 'Amara', 'Okafor', [
                    'email' => 'amara.okafor@demo.unit-hq.test',
                ]);
                JourneySupport::openDeal($world, 'amara', $site, DealStatus::Qualified);
                $unit = JourneySupport::vacantUnit($site, 'SS5');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($signDay)
                    ->toDateString();
                $discountId = Discount::query()
                    ->where('kind', DiscountKind::FreeTime)
                    ->where('name', 'Long-stay promo')
                    ->value('id');
                // 12w commitment → 6 free weeks → at least one full €0 period on monthly cadence.
                JourneySupport::walkInSign(
                    $world,
                    'amara',
                    $unit,
                    $date,
                    discountId: $discountId !== null ? (int) $discountId : null,
                    commitmentWeeks: 12,
                );
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
            'Amara should be pending or active',
        );

        $onSeedEnd = $contract->itemsOn(CarbonImmutable::parse(CastExecutor::SIM_END))
            ->first(fn (ContractItem $i): bool => $i->item_type === 'unit');
        Assert::assertNotNull($onSeedEnd, 'Amara should have a unit version on seed-end');
        Assert::assertNotNull($onSeedEnd->discount_id, 'Amara should carry long-stay discount provenance');
        Assert::assertSame(
            '0.00',
            BillingMath::round2((string) $onSeedEnd->price?->amount),
            'Amara should still be inside the free (€0) window at seed-end',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
