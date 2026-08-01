<?php

declare(strict_types=1);

namespace Tests\Feature\Playbook;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Enums\DealStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\MatchAutomationTriggers;
use App\Jobs\ResumeAutomationRun;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Models\Site;
use App\Support\Automation\AutomationExecutor;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Playbooks\PlaybookCompiler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrolmentEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'UTC'));
        Mail::fake();
        Queue::fake([ExecuteAutomationRun::class, ResumeAutomationRun::class]);
        Event::fake();
        Sanctum::actingAs(Employee::factory()->manager()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lineage_aggregate_bounded(): void
    {
        $site = Site::factory()->create();
        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::LeadChase,
            'name' => 'Lineage chase',
            'is_active' => true,
            'enrolment_filters' => [],
        ]);

        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V1 first'],
            'sort' => 0,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 3,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V1 later'],
            'sort' => 1,
        ]);

        $v1 = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $v1->update(['status' => AutomationStatus::Active]);
        $playbook->update(['automation_id' => $v1->id, 'is_active' => true]);
        AutomationWatchCache::flushAll();

        $dealV1 = Deal::factory()->create([
            'contact_id' => Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->id,
            'site_id' => $site->id,
            'status' => DealStatus::New,
        ]);

        (new MatchAutomationTriggers(
            'created',
            (string) $dealV1->getMorphClass(),
            $dealV1->getKey(),
            [],
            $dealV1->attributesToArray(),
            null,
            null,
        ))->handle();

        $runV1 = AutomationRun::query()->where('automation_id', $v1->id)->latest('id')->first();
        $this->assertNotNull($runV1);
        (new AutomationExecutor)->execute($runV1->fresh());
        $runV1->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $runV1->status);

        // Recompile → v2; leave v1 run in-flight on the old automation.
        $playbook->steps()->delete();
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V2 first'],
            'sort' => 0,
        ]);
        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 5,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'V2 later'],
            'sort' => 1,
        ]);

        $v2 = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $v2->update(['status' => AutomationStatus::Active]);
        AutomationWatchCache::flushAll();

        $dealV2 = Deal::factory()->create([
            'contact_id' => Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper'])->id,
            'site_id' => $site->id,
            'status' => DealStatus::New,
        ]);

        (new MatchAutomationTriggers(
            'created',
            (string) $dealV2->getMorphClass(),
            $dealV2->getKey(),
            [],
            $dealV2->attributesToArray(),
            null,
            null,
        ))->handle();

        $runV2 = AutomationRun::query()->where('automation_id', $v2->id)->latest('id')->first();
        $this->assertNotNull($runV2);
        (new AutomationExecutor)->execute($runV2->fresh());
        $runV2->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $runV2->status);

        // Exit one enrolment so active/exited filters both have rows.
        $this->postJson("/api/automation-runs/{$runV1->id}/cancel")->assertOk();
        $runV1->refresh();
        $this->assertSame(AutomationRunStatus::Cancelled, $runV1->status);

        $show = $this->getJson("/api/playbooks/{$playbook->id}");
        $show->assertOk();
        $show->assertJsonPath('data.active_enrolment_count', 1);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson("/api/playbooks/{$playbook->id}/enrolments?per_page=50");

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($runV1->id, $ids);
        $this->assertContains($runV2->id, $ids);

        $active = $this->getJson("/api/playbooks/{$playbook->id}/enrolments?status=active");
        $active->assertOk();
        $active->assertJsonPath('meta.total', 1);
        $active->assertJsonPath('data.0.id', $runV2->id);
        $active->assertJsonPath('data.0.subject.contact.name', 'Grace Hopper');
        $active->assertJsonPath('data.0.subject.deal.id', $dealV2->id);
        $this->assertGreaterThanOrEqual(1, (int) $active->json('data.0.steps_completed'));

        $exited = $this->getJson("/api/playbooks/{$playbook->id}/enrolments?status=exited");
        $exited->assertOk();
        $exited->assertJsonPath('meta.total', 1);
        $exited->assertJsonPath('data.0.id', $runV1->id);
        $exited->assertJsonPath('data.0.cancel_cause', 'manual');

        // Eager loads + pagination — not one query per enrolment for subjects/nodes.
        $this->assertLessThanOrEqual(25, $queryCount, "Expected bounded queries, got {$queryCount}");
    }
}
