<?php

declare(strict_types=1);

namespace Tests\Feature\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\Contact;

/**
 * Minimal graph builders for automation lifecycle tests.
 */
trait AutomationGraph
{
    /**
     * @param  list<array{key: string, type: AutomationNodeType, config?: array<string, mixed>}>  $nodes
     * @param  list<array{0: string, 1: string, 2?: string}>  $edges  fromKey, toKey, handle?
     * @return array{automation: Automation, nodes: array<string, AutomationNode>}
     */
    protected function buildGraph(array $nodes, array $edges): array
    {
        $automation = Automation::query()->create([
            'name' => 'Lifecycle test',
            'status' => AutomationStatus::Active,
            'version' => 1,
        ]);

        $created = [];
        $x = 0;
        foreach ($nodes as $spec) {
            $type = $spec['type'];
            $created[$spec['key']] = AutomationNode::query()->create([
                'automation_id' => $automation->id,
                'node_key' => $spec['key'],
                'kind' => $type->kind()->value,
                'type' => $type->value,
                'label' => $spec['key'],
                'position_x' => $x,
                'position_y' => 0,
                'config' => $spec['config'] ?? [],
            ]);
            $x += 100;
        }

        foreach ($edges as $edge) {
            [$from, $to] = $edge;
            $handle = $edge[2] ?? 'default';
            AutomationEdge::query()->create([
                'automation_id' => $automation->id,
                'source_node_id' => $created[$from]->id,
                'target_node_id' => $created[$to]->id,
                'source_handle' => $handle,
                'condition' => ['type' => 'always'],
            ]);
        }

        return ['automation' => $automation->fresh(), 'nodes' => $created];
    }

    /** @return array<string, mixed> */
    protected function updateContactConfig(string $firstName): array
    {
        return [
            'objectType' => 'contact',
            'targetRecord' => ['mode' => 'trigger_subject'],
            'updates' => [
                [
                    'property' => 'first_name',
                    'value' => ['kind' => 'static', 'value' => $firstName],
                ],
            ],
        ];
    }

    /**
     * Linear: trigger → n1 → n2 → wait → n4 → n5
     *
     * @return array{automation: Automation, nodes: array<string, AutomationNode>, run: AutomationRun, contact: Contact}
     */
    protected function fiveNodeWaitGraph(
        array $waitConfig = ['mode' => 'relative', 'amount' => 1, 'unit' => 'hours'],
        ?array $guard = null,
    ): array {
        $contact = Contact::factory()->create([
            'first_name' => 'Start',
            'last_name' => 'Contact',
            'email' => 'lifecycle-'.uniqid().'@example.com',
        ]);

        $built = $this->buildGraph([
            ['key' => 'trigger', 'type' => AutomationNodeType::ObjectCreated, 'config' => ['objectType' => 'contact']],
            ['key' => 'n1', 'type' => AutomationNodeType::UpdateObject, 'config' => $this->updateContactConfig('N1')],
            ['key' => 'n2', 'type' => AutomationNodeType::UpdateObject, 'config' => $this->updateContactConfig('N2')],
            ['key' => 'wait', 'type' => AutomationNodeType::Wait, 'config' => $waitConfig],
            ['key' => 'n4', 'type' => AutomationNodeType::UpdateObject, 'config' => $this->updateContactConfig('N4')],
            ['key' => 'n5', 'type' => AutomationNodeType::UpdateObject, 'config' => $this->updateContactConfig('N5')],
        ], [
            ['trigger', 'n1'],
            ['n1', 'n2'],
            ['n2', 'wait'],
            ['wait', 'n4'],
            ['n4', 'n5'],
        ]);

        $run = AutomationRun::query()->create([
            'automation_id' => $built['automation']->id,
            'trigger_node_id' => $built['nodes']['trigger']->id,
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
            'status' => AutomationRunStatus::Pending,
            'trigger_payload' => [
                'lifecycle' => 'created',
                'attributes' => ['first_name' => $contact->first_name],
            ],
            'guard' => $guard,
            'depth' => 0,
        ]);

        return [
            'automation' => $built['automation'],
            'nodes' => $built['nodes'],
            'run' => $run,
            'contact' => $contact,
        ];
    }
}
