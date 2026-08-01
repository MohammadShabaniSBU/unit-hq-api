<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_activation_not_slower_than_billing(): void
    {
        // withSchedule() binds via Artisan::starting — boot the console first.
        $this->artisan('list');

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());

        $this->assertNotEmpty($events, 'Expected scheduled events to be registered');

        $activateIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'contracts:activate')
        );
        $billingIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'billing:run')
                && str_contains((string) $event->command, '--trigger=scheduled')
        );

        $this->assertNotFalse($activateIndex, 'contracts:activate must be scheduled');
        $this->assertNotFalse($billingIndex, 'billing:run --trigger=scheduled must be scheduled');

        /** @var Event $activate */
        $activate = $events[$activateIndex];
        /** @var Event $billing */
        $billing = $events[$billingIndex];

        // Hourly cron for both — activation must not be slower (expression equal or more frequent).
        $this->assertSame('0 * * * *', $activate->expression);
        $this->assertSame('0 * * * *', $billing->expression);

        $this->assertLessThan(
            $billingIndex,
            $activateIndex,
            'contracts:activate must be registered before billing:run so same-tick runs activate first',
        );
    }
}
