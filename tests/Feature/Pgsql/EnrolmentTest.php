<?php

declare(strict_types=1);

namespace Tests\Feature\Pgsql;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\MatchAutomationTriggers;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\Contact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Postgres-only: single_active_run_per_subject active_key uniqueness.
 */
class EnrolmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_active_per_subject_race(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Race / partial-unique test is Postgres-only.');
        }

        Event::fake();
        Queue::fake([ExecuteAutomationRun::class]);

        $contact = Contact::factory()->create([
            'email' => 'enrol-race-'.uniqid().'@example.com',
        ]);

        $automation = Automation::query()->create([
            'name' => 'Single enrolment',
            'status' => AutomationStatus::Active,
            'version' => 1,
            'single_active_run_per_subject' => true,
        ]);

        $trigger = AutomationNode::query()->create([
            'automation_id' => $automation->id,
            'node_key' => 'trigger',
            'kind' => 'trigger',
            'type' => 'trigger.object_created',
            'label' => 'Contact created',
            'position_x' => 0,
            'position_y' => 0,
            'config' => ['objectType' => 'contact'],
        ]);

        $activeKey = $contact->getMorphClass().':'.$contact->getKey();

        AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'trigger_node_id' => $trigger->id,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'status' => AutomationRunStatus::Waiting,
            'active_key' => $activeKey,
            'depth' => 0,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        AutomationRun::query()->create([
            'automation_id' => $automation->id,
            'trigger_node_id' => $trigger->id,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'status' => AutomationRunStatus::Pending,
            'active_key' => $activeKey,
            'depth' => 0,
        ]);
    }

    public function test_matcher_skips_when_active_enrolment_exists(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Enrolment uniqueness coverage is Postgres-oriented.');
        }

        Queue::fake([ExecuteAutomationRun::class]);

        $loaded = \Tests\Support\AutomationFixtureLoader::load('single_enrolment_per_subject');
        $automation = $loaded['automation'];
        $contact = Contact::factory()->create([
            'email' => 'enrol-skip-'.uniqid().'@example.com',
        ]);

        (new MatchAutomationTriggers(
            'created',
            (string) $contact->getMorphClass(),
            $contact->getKey(),
            [],
            $contact->attributesToArray(),
            null,
            null,
        ))->handle();

        (new MatchAutomationTriggers(
            'created',
            (string) $contact->getMorphClass(),
            $contact->getKey(),
            [],
            $contact->attributesToArray(),
            null,
            null,
        ))->handle();

        $this->assertSame(
            1,
            AutomationRun::query()->where('automation_id', $automation->id)->count(),
        );
    }
}
