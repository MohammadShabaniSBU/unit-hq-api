<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\ContractStatus;
use App\Enums\DepositSettlementOutcome;
use App\Models\Contract;
use App\Models\DepositSettlement;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * The Kellys — two units, one contact.
 *
 * One unit vacated last month with a deposit deduction; the other stays active.
 * Multi-contract panel + settlement with deduction.
 */
final class TheKellys extends Journey
{
    public static function handle(): string
    {
        return 'kellys';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 120;
        $secondDay = $end - 90;
        $vacateDay = $end - 30;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'kellys', 'Pat', 'Kelly', [
                    'email' => 'pat.kelly@demo.unit-hq.test',
                    'company' => 'The Kellys',
                ]);
                JourneySupport::openDeal($world, 'kellys', $site);
                $unitA = JourneySupport::vacantUnit($site, 'SS3');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'kellys', $unitA, $date);
                $world->remember('kellys.contract_a', JourneySupport::contract($world, 'kellys'));
                JourneySupport::markSteadyPayer($world, 'kellys');
            },
            $secondDay => static function (DemoWorld $world) use ($secondDay): void {
                $site = $world->site('madrid');
                $unitB = JourneySupport::vacantUnit($site, 'SS4');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($secondDay)
                    ->toDateString();
                // Second contract under a sibling handle so helpers don't overwrite.
                $world->remember('kellys_b.contact', $world->contact('kellys.contact'));
                JourneySupport::walkInSign($world, 'kellys_b', $unitB, $date);
                $world->remember('kellys.contract_b', JourneySupport::contract($world, 'kellys_b'));
                JourneySupport::markSteadyPayer($world, 'kellys_b');
            },
            $vacateDay => static function (DemoWorld $world) use ($vacateDay): void {
                // Vacate contract A (first unit) with deposit deduction.
                $world->remember('kellys.contract', $world->get('kellys.contract_a'));
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($vacateDay)
                    ->toDateString();
                JourneySupport::startMissingPayments($world, 'kellys');
                JourneySupport::vacate(
                    $world,
                    'kellys',
                    $date,
                    DepositSettlementOutcome::Deducted,
                    [['amount' => '50.00', 'reason' => 'Cleaning fee']],
                );
                $world->remember('kellys.contract_a', JourneySupport::contract($world, 'kellys'));
                // Restore active B as the primary handle contract for standing orders.
                $world->remember('kellys.contract', $world->get('kellys.contract_b'));
                JourneySupport::markSteadyPayer($world, 'kellys_b');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contact = $world->contact('kellys.contact');
        $contracts = Contract::query()->where('contact_id', $contact->id)->get();
        Assert::assertGreaterThanOrEqual(2, $contracts->count());

        Assert::assertTrue(
            $contracts->contains(static fn (Contract $c): bool => $c->status === ContractStatus::Active),
            'Kellys should still have an active contract',
        );
        Assert::assertTrue(
            $contracts->contains(static fn (Contract $c): bool => $c->status === ContractStatus::Ended),
            'Kellys should have an ended contract',
        );

        $ended = $contracts->first(
            static fn (Contract $c): bool => $c->status === ContractStatus::Ended,
        );
        Assert::assertNotNull($ended);
        Assert::assertTrue(
            DepositSettlement::query()
                ->where('contract_id', $ended->id)
                ->where('outcome', DepositSettlementOutcome::Deducted)
                ->exists(),
            'Kellys vacated unit should have a deducted deposit settlement',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
