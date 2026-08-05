<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Enums\PaymentRequestStatus;
use App\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Rafa Núñez — payment-link lifecycle.
 *
 * Link sent in-thread, paid via synthetic Stripe webhook. S11 flagship path.
 */
final class RafaNunez extends Journey
{
    public static function handle(): string
    {
        return 'rafa';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 40;
        $linkDay = $end - 6;
        $payDay = $end - 4;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'rafa', 'Rafa', 'Núñez', [
                    'email' => 'rafa.nunez@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'rafa', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS2');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'rafa', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'rafa');
            },
            $linkDay => static function (DemoWorld $world): void {
                JourneySupport::startMissingPayments($world, 'rafa');
                $request = JourneySupport::createPaymentLink($world, 'rafa');
                JourneySupport::sendSms(
                    $world,
                    'rafa',
                    'Aquí tiene su enlace de pago: /pay/'.$request->token,
                );
            },
            $payDay => static function (DemoWorld $world): void {
                JourneySupport::payViaLink($world, 'rafa');
                JourneySupport::markSteadyPayer($world, 'rafa');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        /** @var PaymentRequest $request */
        $request = $world->get('rafa.payment_request');
        Assert::assertSame(PaymentRequestStatus::Paid, $request->fresh()->status);
        Assert::assertNotNull($request->fresh()->paid_payment_id);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
