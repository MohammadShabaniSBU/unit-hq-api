<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Models\Message;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Pilar Santos — WhatsApp window dance.
 *
 * Template outside the session window → inbound reply opens the window →
 * free-form exchange. Last inbound is ~3h before seed-end so the window stays open.
 */
final class PilarSantos extends Journey
{
    public static function handle(): string
    {
        return 'pilar';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $startDay = $end - 45;
        $templateDay = $end - 10;
        $replyDay = $end - 2;
        $sessionDay = $end - 1;
        $openWindowDay = $end;

        return [
            $startDay => static function (DemoWorld $world) use ($startDay): void {
                $site = $world->site('madrid');
                JourneySupport::createContact($world, 'pilar', 'Pilar', 'Santos', [
                    'email' => 'pilar.santos@demo.keevaris.test',
                ]);
                JourneySupport::openDeal($world, 'pilar', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS2');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($startDay)
                    ->toDateString();
                JourneySupport::walkInSign($world, 'pilar', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'pilar');
            },
            $templateDay => static function (DemoWorld $world): void {
                JourneySupport::sendWhatsAppTemplate(
                    $world,
                    'pilar',
                    'Hola Pilar, le escribimos desde Keevaris sobre su contrato.',
                );
            },
            $replyDay => static function (DemoWorld $world): void {
                JourneySupport::inboundWhatsApp(
                    $world,
                    'pilar',
                    'Hola, sí — ¿pueden enviarme el enlace de pago?',
                );
            },
            $sessionDay => static function (DemoWorld $world): void {
                JourneySupport::sendWhatsAppSession(
                    $world,
                    'pilar',
                    'Claro, aquí tiene el enlace. Cualquier duda estamos aquí.',
                );
            },
            $openWindowDay => static function (DemoWorld $world): void {
                $instant = CarbonImmutable::parse(CastExecutor::SIM_END)
                    ->startOfDay()
                    ->setTime(12, 0)
                    ->subHours(3);
                Carbon::setTestNow($instant);
                CarbonImmutable::setTestNow($instant);
                JourneySupport::inboundWhatsApp(
                    $world,
                    'pilar',
                    'Perfecto, gracias!',
                );
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        $contact = $world->contact('pilar.contact');
        $thread = MessageThread::query()
            ->where('contact_id', $contact->id)
            ->where('channel', Channel::Whatsapp)
            ->latest('last_inbound_at')
            ->first();
        Assert::assertNotNull($thread, 'Pilar should have a WhatsApp thread');
        Assert::assertNotNull($thread->last_inbound_at);

        $seedEnd = CarbonImmutable::parse(CastExecutor::SIM_END)->setTime(12, 0, 0);
        $hoursSinceInbound = $thread->last_inbound_at->diffInHours($seedEnd);
        Assert::assertLessThanOrEqual(
            6,
            abs($hoursSinceInbound),
            'WhatsApp window should still be open near seed-end (~3h inbound)',
        );

        Assert::assertGreaterThanOrEqual(
            3,
            Message::query()->where('message_thread_id', $thread->id)->count(),
        );
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
