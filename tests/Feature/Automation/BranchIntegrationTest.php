<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationRunStepStatus;
use App\Jobs\ResumeAutomationRun;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Support\Automation\AutomationExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BranchIntegrationTest extends TestCase
{
    use AutomationGraph;
    use RefreshDatabase;

    public function test_skipped_logging_and_post_wait_snapshot(): void
    {
        Queue::fake([ResumeAutomationRun::class]);
        Carbon::setTestNow('2026-08-01 10:00:00');

        $contact = Contact::factory()->create([
            'first_name' => 'BranchSnap',
            'last_name' => 'Contact',
            'email' => 'branch-'.uniqid().'@example.com',
        ]);

        $built = $this->buildGraph([
            ['key' => 'trigger', 'type' => AutomationNodeType::ObjectCreated, 'config' => ['objectType' => 'contact']],
            [
                'key' => 'wait',
                'type' => AutomationNodeType::Wait,
                'config' => ['mode' => 'relative', 'amount' => 1, 'unit' => 'hours'],
            ],
            [
                'key' => 'branch',
                'type' => AutomationNodeType::Branch,
                'config' => [
                    'filters' => [
                        'logic' => 'and',
                        'conditions' => [
                            [
                                'field' => 'first_name',
                                'operator' => 'equals',
                                'value' => 'BranchSnap',
                            ],
                        ],
                    ],
                ],
            ],
            ['key' => 'yes', 'type' => AutomationNodeType::UpdateObject, 'config' => $this->updateContactConfig('YesPath')],
            ['key' => 'no', 'type' => AutomationNodeType::UpdateObject, 'config' => $this->updateContactConfig('NoPath')],
        ], [
            ['trigger', 'wait'],
            ['wait', 'branch'],
            ['branch', 'yes', 'true'],
            ['branch', 'no', 'false'],
        ]);

        $run = AutomationRun::query()->create([
            'automation_id' => $built['automation']->id,
            'trigger_node_id' => $built['nodes']['trigger']->id,
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
            'status' => AutomationRunStatus::Pending,
            'trigger_payload' => [
                'lifecycle' => 'created',
                'attributes' => [
                    'first_name' => 'BranchSnap',
                    'last_name' => $contact->last_name,
                    'email' => $contact->email,
                ],
                'custom_attributes' => [],
            ],
            'depth' => 0,
        ]);

        (new AutomationExecutor)->execute($run);
        $run->refresh();
        $this->assertSame(AutomationRunStatus::Waiting, $run->status);

        // Mutate live subject during wait — branch must still use snapshot.
        $contact->update(['first_name' => 'MutatedLive']);

        Carbon::setTestNow('2026-08-01 11:05:00');
        (new ResumeAutomationRun($run->id))->handle(app(AutomationExecutor::class));
        $run->refresh();

        $this->assertSame(AutomationRunStatus::Succeeded, $run->status);

        $yesSteps = $run->steps()->where('node_id', $built['nodes']['yes']->id)->get();
        $noSteps = $run->steps()->where('node_id', $built['nodes']['no']->id)->get();

        $this->assertSame(1, $yesSteps->count());
        $this->assertSame(AutomationRunStepStatus::Succeeded, $yesSteps->first()->status);

        $this->assertSame(1, $noSteps->count());
        $this->assertSame(AutomationRunStepStatus::Skipped, $noSteps->first()->status);
        $this->assertStringContainsString('not taken', (string) ($noSteps->first()->input['reason'] ?? ''));

        $branchStep = $run->steps()->where('node_id', $built['nodes']['branch']->id)->first();
        $this->assertNotNull($branchStep);
        $this->assertSame('true', $branchStep->output['handle'] ?? null);
        $this->assertSame('snapshot', $branchStep->output['source'] ?? null);

        $contact->refresh();
        $this->assertSame('YesPath', $contact->first_name);

        Carbon::setTestNow();
    }
}
