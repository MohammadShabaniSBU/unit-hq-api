<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use App\Enums\TransferPricingMode;
use App\Models\ContractTransfer;
use App\Models\Message;
use App\Models\UnitOccupancy;
use App\Support\Billing\BillingMath;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Marcus Webb — the mockup conversation made real.
 *
 * An 8 m² (SS4) tenant in Madrid for most of the simulation. Near seed-end he
 * texts asking for something bigger; we transfer him to SS6 and bump the rate
 * by €40. End state: active, transferred, SMS thread with the size question.
 */
final class MarcusWebb extends Journey
{
    public static function handle(): string
    {
        return 'marcus';
    }

    public static function script(): array
    {
        $transferDay = self::endOffset() - 10;

        return [
            0 => static function (DemoWorld $world): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'marcus', 'Marcus', 'Webb', [
                    'email' => 'marcus.webb@demo.unit-hq.test',
                ]);
                JourneySupport::openDeal($world, 'marcus', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS4');
                JourneySupport::walkInSign(
                    $world,
                    'marcus',
                    $unit,
                    CarbonImmutable::parse(CastExecutor::SIM_START)->toDateString(),
                );
                JourneySupport::markSteadyPayer($world, 'marcus');
            },
            $transferDay => static function (DemoWorld $world) use ($transferDay): void {
                JourneySupport::inboundSms(
                    $world,
                    'marcus',
                    'Hi — do you have anything bigger than my 8m²? Looking to upsize.',
                );
                JourneySupport::sendSms(
                    $world,
                    'marcus',
                    'We can move you to a larger unit. I will prepare the transfer.',
                );

                $site = $world->site('madrid');
                $destination = JourneySupport::vacantUnit($site, 'SS6');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($transferDay)
                    ->toDateString();

                JourneySupport::transfer(
                    $world,
                    'marcus',
                    $destination,
                    $date,
                    TransferPricingMode::RetainRate,
                    'Upsize after size question SMS',
                );

                $contract = JourneySupport::contract($world, 'marcus')->fresh(['items.price']);
                $item = $contract->items()->where('item_type', 'unit')->whereNull('effective_to')->firstOrFail();
                $current = BillingMath::round2((string) $item->price->amount);
                $bumped = BillingMath::round2(bcadd($current, '40.00', 2));
                JourneySupport::scheduleRateChange($world, 'marcus', $bumped, $date);
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'marcus')->fresh();
        Assert::assertSame(ContractStatus::Active, $contract->status);
        Assert::assertTrue(
            ContractTransfer::query()->where('contract_id', $contract->id)->exists(),
            'Marcus should have a transfer row',
        );
        Assert::assertGreaterThanOrEqual(
            2,
            UnitOccupancy::query()->where('contract_id', $contract->id)->count(),
            'Marcus should have two occupancy rows (origin + destination)',
        );
        Assert::assertTrue(
            Message::query()
                ->whereHas('thread', static fn ($q) => $q->where('contact_id', $world->contact('marcus.contact')->id))
                ->where('body_text', 'like', '%bigger%')
                ->exists(),
            'Marcus SMS size question should exist',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
