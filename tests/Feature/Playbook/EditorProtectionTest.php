<?php

declare(strict_types=1);

namespace Tests\Feature\Playbook;

use App\Enums\AutomationStatus;
use App\Enums\PlaybookKind;
use App\Enums\PlaybookStepAction;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Support\Playbooks\PlaybookCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_compiled_graphs_read_only(): void
    {
        $playbook = Playbook::query()->create([
            'kind' => PlaybookKind::DebtProcess,
            'name' => 'Protected',
            'is_active' => false,
            'enrolment_filters' => [],
        ]);

        PlaybookStep::query()->create([
            'playbook_id' => $playbook->id,
            'offset_days' => 0,
            'action' => PlaybookStepAction::CreateTask,
            'params' => ['title' => 'Only step'],
            'sort' => 0,
        ]);

        $automation = PlaybookCompiler::compile($playbook->fresh(['steps']));
        $this->assertNotNull($automation->playbook_id);

        $show = $this->getJson("/api/automations/{$automation->id}");
        $show->assertOk();
        $show->assertJsonPath('data.playbook_id', $playbook->id);
        $show->assertJsonPath('data.single_active_run_per_subject', true);

        $patch = $this->patchJson("/api/automations/{$automation->id}", [
            'nodes' => [
                [
                    'node_key' => 'trigger',
                    'kind' => 'trigger',
                    'type' => 'trigger.object_created',
                    'label' => 'Hacked',
                    'position_x' => 0,
                    'position_y' => 0,
                    'config' => ['objectType' => 'contact'],
                ],
            ],
            'edges' => [],
        ]);

        $patch->assertStatus(422);
        $patch->assertJsonValidationErrors(['compiled_playbook']);

        // Name-only update remains allowed (graph untouched).
        $nameOnly = $this->patchJson("/api/automations/{$automation->id}", [
            'name' => 'Still compiled',
            'status' => AutomationStatus::Inactive->value,
        ]);
        $nameOnly->assertOk();
        $nameOnly->assertJsonPath('data.name', 'Still compiled');
        $nameOnly->assertJsonPath('data.playbook_id', $playbook->id);
    }
}
