<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\MessageThread;
use App\Models\Site;
use App\Support\Communications\Channel;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\Comms\ContentLibrary;
use Database\Seeders\Demo\Crowd\DemoRng;
use Database\Seeders\Demo\Journeys\JourneySupport;

/**
 * Seed-end guarantee: recent email + call threads so the inbox is never empty.
 * Uses real senders / Aircall injectors only (no raw Message inserts).
 */
final class InboxContentBootstrap
{
    private const EMAIL_SUBJECT = 'Demo inbound';

    public static function apply(DemoWorld $world): void
    {
        $instant = CarbonImmutable::parse(CastExecutor::SIM_END)
            ->startOfDay()
            ->setTime(12, 0);

        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);

        $library = new ContentLibrary(DemoRng::fromEnv());
        $site = $world->site('madrid');
        $operator = self::operator();

        self::seedEmailThreads($world, $site, $library, $operator);
        self::seedCallThreads($world, $operator);

        $world->remember('inbox.content_bootstrapped', true);
    }

    private static function operator(): ?Employee
    {
        $employees = Employee::query()->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return null;
        }

        return $employees->first(
            static fn (Employee $e): bool => $e->role === 'manager' || $e->role === 'Manager'
        ) ?? $employees->first();
    }

    /**
     * Half assigned to operator, rest unassigned — keeps Mine + Unassigned demos useful.
     */
    private static function assignBootstrapThread(?Employee $operator, ?MessageThread $thread, int $index): void
    {
        if ($thread === null) {
            return;
        }

        $assigneeId = ($operator !== null && $index % 2 === 0) ? $operator->id : null;
        $thread->forceFill(['assigned_employee_id' => $assigneeId])->save();
    }

    private static function latestThreadForContact(DemoWorld $world, string $handle, Channel $channel): ?MessageThread
    {
        return MessageThread::query()
            ->where('contact_id', $world->contact("{$handle}.contact")->id)
            ->where('channel', $channel)
            ->orderByDesc('id')
            ->first();
    }

    private static function seedEmailThreads(
        DemoWorld $world,
        Site $site,
        ContentLibrary $library,
        ?Employee $operator,
    ): void {
        $people = [
            ['inbox_mail_a', 'Alicia', 'Mora', '+34600201001', 'inbox.mail.a@demo.unit-hq.test'],
            ['inbox_mail_b', 'Bruno', 'Sanz', '+34600201002', 'inbox.mail.b@demo.unit-hq.test'],
            ['inbox_mail_c', 'Carla', 'Ruiz', '+34600201003', 'inbox.mail.c@demo.unit-hq.test'],
            ['inbox_mail_d', 'Diego', 'Vega', '+34600201004', 'inbox.mail.d@demo.unit-hq.test'],
            ['inbox_mail_e', 'Elena', 'Nieto', '+34600201005', 'inbox.mail.e@demo.unit-hq.test'],
            ['inbox_mail_f', 'Fiona', 'Cruz', '+34600201006', 'inbox.mail.f@demo.unit-hq.test'],
        ];

        $deskReplies = [
            'Claro, le enviamos la información en un momento.',
            'Gracias por escribirnos — ¿prefiere que le llamemos?',
            'Perfecto, podemos ayudarle con eso esta tarde.',
            'Recibido. El precio incluye el acceso 24/7.',
            'Of course — here are the next steps for your unit.',
            'Le confirmamos la disponibilidad. ¿Firmamos hoy?',
        ];

        foreach ($people as $i => [$handle, $first, $last, $phone, $email]) {
            self::ensureContact($world, $handle, $first, $last, $phone, $email, $site);

            JourneySupport::inboundEmail($world, $handle, $library->emailBody());

            $thread = self::latestThreadForContact($world, $handle, Channel::Email);

            if ($thread !== null) {
                $world->remember("{$handle}.email_thread", $thread);
            }

            JourneySupport::sendEmail(
                $world,
                $handle,
                self::EMAIL_SUBJECT,
                $deskReplies[$i] ?? $deskReplies[0],
            );

            JourneySupport::inboundEmail($world, $handle, $library->emailBody());

            $thread = self::latestThreadForContact($world, $handle, Channel::Email);
            self::assignBootstrapThread($operator, $thread, $i);
            if ($thread !== null) {
                $world->remember("{$handle}.email_thread", $thread);
            }
        }
    }

    private static function seedCallThreads(DemoWorld $world, ?Employee $operator): void
    {
        $site = $world->site('madrid');

        $people = [
            ['inbox_call_a', 'Gina', 'Calle', '+34600202001', 'inbox.call.a@demo.unit-hq.test', 'answered_in'],
            ['inbox_call_b', 'Hugo', 'Llano', '+34600202002', 'inbox.call.b@demo.unit-hq.test', 'answered_in'],
            ['inbox_call_c', 'Irene', 'Paz', '+34600202003', 'inbox.call.c@demo.unit-hq.test', 'missed'],
            ['inbox_call_d', 'Javi', 'Sol', '+34600202004', 'inbox.call.d@demo.unit-hq.test', 'missed'],
            ['inbox_call_e', 'Karen', 'Río', '+34600202005', 'inbox.call.e@demo.unit-hq.test', 'voicemail'],
            ['inbox_call_f', 'Luis', 'Mar', '+34600202006', 'inbox.call.f@demo.unit-hq.test', 'answered_out'],
        ];

        foreach ($people as $i => [$handle, $first, $last, $phone, $email, $kind]) {
            self::ensureContact($world, $handle, $first, $last, $phone, $email, $site);

            match ($kind) {
                'answered_in' => JourneySupport::recordCallWrapup(
                    $world,
                    $handle,
                    'reached',
                    'Demo bootstrap inbound call',
                    direction: 'inbound',
                ),
                'missed' => $world->aircall()->missedInbound($phone),
                'voicemail' => JourneySupport::recordCallWrapup(
                    $world,
                    $handle,
                    'voicemail_left',
                    'Demo bootstrap voicemail',
                    direction: 'inbound',
                ),
                'answered_out' => JourneySupport::recordCallWrapup(
                    $world,
                    $handle,
                    'payment_promised',
                    'Demo bootstrap outbound collection call',
                    direction: 'outbound',
                ),
                default => null,
            };

            $thread = self::latestThreadForContact($world, $handle, Channel::Call);
            self::assignBootstrapThread($operator, $thread, $i);
        }
    }

    private static function ensureContact(
        DemoWorld $world,
        string $handle,
        string $first,
        string $last,
        string $phone,
        string $email,
        Site $site,
    ): void {
        if ($world->has("{$handle}.contact")) {
            $world->remember("{$handle}.site", $site);

            return;
        }

        $existing = Contact::query()->where('email', $email)->first();
        if ($existing !== null) {
            $world->remember("{$handle}.contact", $existing);
            $world->remember("{$handle}.email", $email);
            $world->remember("{$handle}.phone", $phone);
            $world->remember("{$handle}.site", $site);

            return;
        }

        JourneySupport::createContact($world, $handle, $first, $last, [
            'email' => $email,
            'phone' => $phone,
            'source_detail' => 'demo_inbox_bootstrap',
        ]);
        $world->remember("{$handle}.site", $site);
    }
}
