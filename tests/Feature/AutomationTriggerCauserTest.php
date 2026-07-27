<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ModelCreated;
use App\Events\ModelUpdated;
use App\Models\Contact;
use App\Support\Automation\AutomationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Causer must be captured at dispatch time. System/cron saves have no auth —
 * event must carry null causer cleanly (not throw).
 */
class AutomationTriggerCauserTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AutomationContext::clear();
        parent::tearDown();
    }

    public function test_system_save_dispatches_model_created_with_null_causer(): void
    {
        Event::fake([ModelCreated::class, ModelUpdated::class]);

        // No actingAs — simulates cron / queued import / billing job.
        $contact = Contact::query()->create([
            'first_name' => 'Sys',
            'last_name' => 'Tem',
            'email' => 'sys@example.com',
        ]);

        Event::assertDispatched(ModelCreated::class, function (ModelCreated $event) use ($contact): bool {
            return $event->subjectType === 'contact'
                && (int) $event->subjectId === (int) $contact->id
                && $event->causerType === null
                && $event->causerId === null;
        });
    }

    public function test_system_update_dispatches_model_updated_with_null_causer(): void
    {
        Event::fake([ModelCreated::class, ModelUpdated::class]);

        $contact = Contact::query()->create([
            'first_name' => 'Sys',
            'last_name' => 'Tem',
            'email' => 'sys@example.com',
        ]);

        Event::fake([ModelUpdated::class]);

        $contact->update(['first_name' => 'Updated']);

        Event::assertDispatched(ModelUpdated::class, function (ModelUpdated $event) use ($contact): bool {
            return $event->subjectType === 'contact'
                && (int) $event->subjectId === (int) $contact->id
                && $event->causerType === null
                && $event->causerId === null
                && array_key_exists('first_name', $event->dirty);
        });
    }

    public function test_automation_context_suppresses_model_events(): void
    {
        Event::fake([ModelCreated::class, ModelUpdated::class]);

        $contact = Contact::query()->create([
            'first_name' => 'Sys',
            'last_name' => 'Tem',
            'email' => 'sys@example.com',
        ]);

        Event::fake([ModelUpdated::class]);

        AutomationContext::run(1, function () use ($contact): void {
            $contact->update(['first_name' => 'FromAutomation']);
        });

        Event::assertNotDispatched(ModelUpdated::class);
    }
}
