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

        $activateHourlyIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'contracts:activate')
                && $event->expression === '0 * * * *'
        );
        $activateDailyIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'contracts:activate')
                && $event->expression === '30 0 * * *'
        );
        $billingEvents = $events->filter(
            fn (Event $event): bool => str_contains((string) $event->command, 'billing:run')
        );
        $billingIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'billing:run')
                && str_contains((string) $event->command, '--trigger=scheduled')
        );
        $autopayIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'autopay:collect')
                && str_contains((string) $event->command, '--trigger=sweep')
        );
        $delinquencyIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'delinquency:run')
        );
        $accessSyncIndex = $events->search(
            fn (Event $event): bool => str_contains((string) $event->command, 'access:sync')
        );

        $this->assertNotFalse($activateHourlyIndex, 'contracts:activate must stay hourly');
        $this->assertNotFalse($activateDailyIndex, 'contracts:activate must also run immediately before the daily billing run');
        $this->assertCount(1, $billingEvents, 'Exactly one billing:run job must be scheduled');
        $this->assertNotFalse($billingIndex, 'billing:run --trigger=scheduled must be scheduled');
        $this->assertNotFalse($autopayIndex, 'autopay:collect --trigger=sweep must be scheduled');
        $this->assertNotFalse($delinquencyIndex, 'delinquency:run must be scheduled');
        $this->assertNotFalse($accessSyncIndex, 'access:sync must be scheduled');

        /** @var Event $activate */
        $activate = $events[$activateHourlyIndex];
        /** @var Event $billing */
        $billing = $events[$billingIndex];
        /** @var Event $autopay */
        $autopay = $events[$autopayIndex];
        /** @var Event $delinquency */
        $delinquency = $events[$delinquencyIndex];
        /** @var Event $accessSync */
        $accessSync = $events[$accessSyncIndex];

        $this->assertSame('0 * * * *', $activate->expression);
        $this->assertSame('30 0 * * *', $billing->expression);
        $this->assertSame('0 * * * *', $autopay->expression);
        // Daily — after bill/collect in registration order; idempotent so frequency is safe.
        $this->assertSame('0 0 * * *', $delinquency->expression);
        $this->assertSame('0 * * * *', $accessSync->expression);

        $this->assertLessThan(
            $billingIndex,
            $activateDailyIndex,
            'daily contracts:activate must be registered immediately before billing:run',
        );
        $this->assertLessThan(
            $autopayIndex,
            $billingIndex,
            'billing:run must be registered before autopay:collect so same-tick runs bill first',
        );
        $this->assertLessThan(
            $delinquencyIndex,
            $autopayIndex,
            'autopay:collect must be registered before delinquency:run',
        );
    }
}
