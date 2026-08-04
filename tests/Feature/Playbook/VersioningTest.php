<?php

declare(strict_types=1);

namespace Tests\Feature\Playbook;

use App\Enums\AutomationCancelCause;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\MatchAutomationTriggers;
use App\Jobs\ResumeAutomationRun;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Enums\DealStatus;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Playbooks\PlaybookCompiler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Support\AuthenticatesAsEmployee;

class VersioningTest extends TestCase
{
    use RefreshDatabase;
    use AuthenticatesAsEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsEmployee();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        Mail::fake();
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);
        Event::fake();
        Employee::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inflight_finish_old_graph(): void
    {
        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Versioned chase',
            'is_active' => true,
            'enrolment_filters' => [],
        ]);

        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V1 task'],
            'sort' => 0,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 2,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V1 later'],
            'sort' => 1,
        ]);

        $v1 = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $v1->update(['status' => AutomationStatus::Active]);
        $playbook->update(['is_active' => true, 'automation_id' => $v1->id]);
        AutomationWatchCache::flushAll();

        $contact = Contact::factory()->create([
            'email' => 'version-'.uniqid().'@example.com',
        ]);

        $deal = \App\Models\Deal::factory()->create([
            'contact_id' => $contact->id,
            'site_id' => \App\Models\Site::factory()->create()->id,
            'status' => DealStatus::New,
        ]);

        (new MatchAutomationTriggers(
            'created',
            (string) $deal->getMorphClass(),
            $deal->getKey(),
            [],
            $deal->attributesToArray(),
            null,
            null,
        ))->handle();

        $oldRun = AutomationRun::query()->where('automation_id', $v1->id)->latest('id')->first();
        $this->assertNotNull($oldRun);
        (new AutomationExecutor)->execute($oldRun->fresh());
        $oldRun->refresh();
        $this->assertSame(
            AutomationRunStatus::Waiting,
            $oldRun->status,
            (string) $oldRun->error,
        );

        // Edit playbook → new compiled version; old automation deactivated.
        $playbook->steps()->delete();
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V2 only'],
            'sort' => 0,
        ]);

        $v2 = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $playbook->refresh();
        $v1->refresh();

        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame($v2->id, $playbook->automation_id);
        $this->assertSame(AutomationStatus::Inactive, $v1->status);
        $this->assertSame($playbook->id, $v2->playbook_id);

        // In-flight run still references v1 and can resume on the old graph.
        $this->assertSame($v1->id, $oldRun->fresh()->automation_id);
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'UTC'));
        (new AutomationExecutor)->execute($oldRun->fresh());
        $oldRun->refresh();
        $this->assertSame(AutomationRunStatus::Succeeded, $oldRun->status);
        $this->assertTrue(
            \App\Models\Task::query()->where('title', 'V1 later')->exists(),
        );

        // Fresh enrolment + bulk exit with superseded.
        $deal2 = \App\Models\Deal::factory()->create([
            'contact_id' => Contact::factory()->create()->id,
            'site_id' => $deal->site_id,
            'status' => DealStatus::New,
        ]);
        // Need a wait so we have an in-flight run to cancel.
        $playbook->steps()->delete();
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V3 first'],
            'sort' => 0,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 5,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V3 later'],
            'sort' => 1,
        ]);
        $v3 = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $v3->update(['status' => AutomationStatus::Active]);
        AutomationWatchCache::flushAll();

        (new MatchAutomationTriggers(
            'created',
            (string) $deal2->getMorphClass(),
            $deal2->getKey(),
            [],
            $deal2->attributesToArray(),
            null,
            null,
        ))->handle();

        $inflight = AutomationRun::query()->where('automation_id', $v3->id)->latest('id')->first();
        $this->assertNotNull($inflight);
        (new AutomationExecutor)->execute($inflight->fresh());
        $inflight->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $inflight->status);

        $response = $this->postJson("/api/playbooks/{$playbook->id}/exit-enrolments");
        $response->assertOk();
        $this->assertSame(1, $response->json('data.cancelled'));

        $inflight->refresh();
        $this->assertSame(AutomationRunStatus::Cancelled, $inflight->status);
        $this->assertSame(AutomationCancelCause::Superseded, $inflight->cancel_cause);
    }
}
