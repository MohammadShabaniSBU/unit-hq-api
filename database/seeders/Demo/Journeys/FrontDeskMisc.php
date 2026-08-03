<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use App\Models\CallWrapup;
use App\Models\CommsTriage;
use App\Models\MessageThread;
use App\Support\Communications\Channel;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use PHPUnit\Framework\Assert;

/**
 * Front-desk misc — triage queue + call textures + seed-end unread staging.
 *
 * Voicemail persona, unmatched triage (2 email + 1 SMS + 1 unknown caller),
 * wrong-number call, WA closing-today peer, and unread threads for the inbox badge.
 */
final class FrontDeskMisc extends Journey
{
    public static function handle(): string
    {
        return 'front_desk';
    }

    public static function script(): array
    {
        $end = self::endOffset();
        $day = $end - 3;
        $finaleDay = $end - 1;

        return [
            $day => static function (DemoWorld $world): void {
                JourneySupport::createContact($world, 'voicemail', 'Vera', 'Voicemail', [
                    'email' => 'vera.voicemail@demo.unit-hq.test',
                    'phone' => '+34600111001',
                ]);
                JourneySupport::recordCallWrapup(
                    $world,
                    'voicemail',
                    'voicemail_left',
                    'Left voicemail about unit availability',
                    direction: 'inbound',
                );

                JourneySupport::createContact($world, 'wrong_number', 'Walter', 'Wrong', [
                    'email' => 'walter.wrong@demo.unit-hq.test',
                    'phone' => '+34600111002',
                ]);
                JourneySupport::recordCallWrapup(
                    $world,
                    'wrong_number',
                    'wrong_number',
                    'Dialed wrong number',
                    direction: 'outbound',
                );

                $world->inbound()->email(
                    'stranger.one@unknown.example',
                    'Hi, do you have units near the airport?',
                );
                $world->inbound()->sms(
                    '+34999888777',
                    'Need a locker for 2 weeks please',
                );
                $world->inbound()->email(
                    'stranger.two@unknown.example',
                    'Is this the storage place?',
                );
                $world->aircall()->unknownMissed('+34999000111');
            },
            $finaleDay => static function (DemoWorld $world) use ($end): void {
                $site = $world->site('barcelona');
                JourneySupport::createContact($world, 'wa_closing', 'Carmen', 'Vega', [
                    'email' => 'carmen.vega@demo.unit-hq.test',
                    'phone' => '+34600111003',
                ]);
                JourneySupport::openDeal($world, 'wa_closing', $site);
                $unit = JourneySupport::vacantUnit($site, 'SS4');
                $date = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays(max(0, $end - 40))
                    ->toDateString();
                JourneySupport::walkInSign($world, 'wa_closing', $unit, $date);
                JourneySupport::markSteadyPayer($world, 'wa_closing');

                JourneySupport::sendWhatsAppTemplate(
                    $world,
                    'wa_closing',
                    'Hola Carmen, le escribimos desde Unit HQ.',
                );

                $closingInstant = CarbonImmutable::parse(CastExecutor::SIM_END)
                    ->startOfDay()
                    ->setTime(12, 0)
                    ->subHours(21);
                Carbon::setTestNow($closingInstant);
                CarbonImmutable::setTestNow($closingInstant);
                JourneySupport::inboundWhatsApp(
                    $world,
                    'wa_closing',
                    'Hola, recibí su mensaje — gracias.',
                );

                // Restore noon for the rest of the day's unread staging.
                $noon = CarbonImmutable::parse(CastExecutor::SIM_START)
                    ->addDays($end - 1)
                    ->setTime(12, 0);
                Carbon::setTestNow($noon);
                CarbonImmutable::setTestNow($noon);

                $handles = [
                    ['unread_a', 'Ana', 'Unread', '+34600112001', 'ana.unread@demo.unit-hq.test', 'sms'],
                    ['unread_b', 'Bruno', 'Unread', '+34600112002', 'bruno.unread@demo.unit-hq.test', 'email'],
                    ['unread_c', 'Clara', 'Unread', '+34600112003', 'clara.unread@demo.unit-hq.test', 'whatsapp'],
                    ['unread_d', 'Diego', 'Unread', '+34600112004', 'diego.unread@demo.unit-hq.test', 'sms'],
                    ['unread_e', 'Elena', 'Unread', '+34600112005', 'elena.unread@demo.unit-hq.test', 'email'],
                ];

                foreach ($handles as [$handle, $first, $last, $phone, $email, $channel]) {
                    JourneySupport::createContact($world, $handle, $first, $last, [
                        'email' => $email,
                        'phone' => $phone,
                    ]);

                    match ($channel) {
                        'sms' => JourneySupport::inboundSms($world, $handle, 'Hola, ¿pueden ayudarme?'),
                        'whatsapp' => JourneySupport::inboundWhatsApp($world, $handle, 'Hola por WhatsApp'),
                        default => JourneySupport::inboundEmail($world, $handle, 'Hola, necesito información.'),
                    };
                }

                JourneySupport::createContact($world, 'missed_a', 'Mia', 'Missed', [
                    'phone' => '+34600113001',
                    'email' => 'mia.missed@demo.unit-hq.test',
                ]);
                $world->aircall()->missedInbound('+34600113001');

                JourneySupport::createContact($world, 'missed_b', 'Nico', 'Missed', [
                    'phone' => '+34600113002',
                    'email' => 'nico.missed@demo.unit-hq.test',
                ]);
                $world->aircall()->missedInbound('+34600113002');
            },
        ];
    }

    public static function assertEndState(DemoWorld $world): void
    {
        Assert::assertGreaterThanOrEqual(
            4,
            CommsTriage::query()->where('status', 'pending')->count(),
            'Front desk should leave ≥4 pending triage rows',
        );

        Assert::assertTrue(
            CallWrapup::query()->where('disposition', 'voicemail_left')->exists(),
        );
        Assert::assertTrue(
            CallWrapup::query()->where('disposition', 'wrong_number')->exists(),
        );

        Assert::assertGreaterThanOrEqual(
            7,
            MessageThread::query()->where('unread_count', '>', 0)->count(),
            'Inbox should open with ≥7 unread threads',
        );

        $closing = MessageThread::query()
            ->where('contact_id', $world->contact('wa_closing.contact')->id)
            ->where('channel', Channel::Whatsapp)
            ->latest('last_inbound_at')
            ->first();
        Assert::assertNotNull($closing);
        Assert::assertNotNull($closing->last_inbound_at);
    }

    private static function endOffset(): int
    {
        return (int) CarbonImmutable::parse(CastExecutor::SIM_START)
            ->diffInDays(CarbonImmutable::parse(CastExecutor::SIM_END));
    }
}
