<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\AutopayAttemptStatus;
use App\Models\AutopayAttempt;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Ana Coloma — autopay-failing.
 *
 * Card on file, then insufficient_funds twice; manual retry remains pending.
 * End state: amber autopay chip + failed attempts.
 */
final class HannahCole extends Journey
{
    public static function handle(): string
    {
        return 'hannah';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 50;
        $cardDay = $end - 20;
        $fail1 = $end - 10;
        $fail2 = $end - 5;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'hannah', 'Ana', 'Coloma', [
                    'email' => 'ana.coloma@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'hannah', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS3');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'hannah', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'hannah');
            },
            $cardDay => static function (DemoWorld $world): void {
                JourneySupport::startMissingPayments($world, 'hannah');
                JourneySupport::enableAutopay($world, 'hannah');
            },
            $fail1 => static function (DemoWorld $world): void {
                JourneySupport::failAutopay($world, 'hannah', 'insufficient_funds');
            },
            $fail2 => static function (DemoWorld $world): void {
                JourneySupport::failAutopay($world, 'hannah', 'insufficient_funds');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contract = JourneySupport::contract($world, 'hannah')->fresh();
        Assert::assertTrue((bool) $contract->autopay_enabled);
        Assert::assertNotNull($contract->payment_method_id);

        $failed = AutopayAttempt::query()
            ->where('contract_id', $contract->id)
            ->where('status', AutopayAttemptStatus::Failed)
            ->where('decline_code', 'insufficient_funds')
            ->count();
        Assert::assertGreaterThanOrEqual(2, $failed);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
