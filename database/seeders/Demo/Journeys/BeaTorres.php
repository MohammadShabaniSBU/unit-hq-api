<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Models\ChannelSuppression;
use App\Models\Message;
use App\Support\Communications\Channel;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Bea Torres — suppression story.
 *
 * Hard bounce → suppressed email, SMS fallback thread. Suppressed badge +
 * sequence skipped-with-reason surfaces.
 */
final class BeaTorres extends Journey
{
    public static function handle(): string
    {
        return 'bea';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 30;
        $bounceDay = $end - 20;
        $smsDay = $end - 18;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'bea', 'Bea', 'Torres', [
                    'email' => 'bea.torres@demo.unit-hq.test',
                ]);
                JourneySupport::openDeal($world, 'bea', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS2');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'bea', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'bea');
            },
            $bounceDay => static function (DemoWorld $world): void {
                JourneySupport::sendEmail(
                    $world,
                    'bea',
                    'Your invoice is ready',
                    'Please find your latest invoice attached.',
                );
                JourneySupport::hardBounceLastEmail($world, 'bea');
            },
            $smsDay => static function (DemoWorld $world): void {
                JourneySupport::sendSms(
                    $world,
                    'bea',
                    'We could not reach you by email — here is a quick SMS update about your account.',
                );
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $email = (string) $world->get('bea.email');
        Assert::assertTrue(
            ChannelSuppression::query()
                ->where('channel', Channel::Email)
                ->where('address', $email)
                ->exists(),
            'Bea email should be suppressed after hard bounce',
        );

        Assert::assertTrue(
            Message::query()
                ->whereHas('thread', static fn ($q) => $q
                    ->where('contact_id', $world->contact('bea.contact')->id)
                    ->where('channel', Channel::Sms))
                ->exists(),
            'Bea should have an SMS fallback thread',
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
