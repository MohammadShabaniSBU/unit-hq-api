<?php

declare(strict_types=1);

namespace App\Support\Playbooks;

use App\Enums\AutomationStatus;
use App\Enums\PlaybookStepAction;
use App\Models\Automation;
use App\Models\AutomationEdge;
use App\Models\AutomationNode;
use App\Models\Playbook;
use App\Models\PlaybookStep;
use App\Support\Automation\AutomationWatchCache;
use App\Support\Automation\CreateObjectValidator;
use App\Support\Automation\TargetRecordValidator;
use App\Support\Automation\TriggerConfigValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compiles a linear playbook into a new automation graph version.
 */
final class PlaybookCompiler
{
    public static function compile(Playbook $playbook): Automation
    {
        $playbook->loadMissing('steps');

        $kind = PlaybookKindRegistry::for($playbook->kind);
        $filters = $playbook->enrolment_filters ?? [];
        $kind->validateFilters($filters);

        foreach ($playbook->steps as $step) {
            if (! in_array($step->action, $kind->allowedActions(), true)) {
                throw ValidationException::withMessages([
                    'steps' => "Action [{$step->action->value}] is not allowed for playbook kind [{$playbook->kind->value}].",
                ]);
            }
        }

        [$nodes, $edges] = self::buildGraph($playbook, $kind, $filters);

        $nodes = TargetRecordValidator::normalizeNodes($nodes);
        $nodes = CreateObjectValidator::normalizeNodes($nodes);
        TargetRecordValidator::assertValid($nodes, $edges);
        CreateObjectValidator::assertValid($nodes);
        TriggerConfigValidator::assertValid($nodes);

        return DB::transaction(function () use ($playbook, $kind, $filters, $nodes, $edges): Automation {
            $previousId = $playbook->automation_id;

            if ($previousId !== null) {
                Automation::query()
                    ->whereKey($previousId)
                    ->update(['status' => AutomationStatus::Inactive->value]);
            }

            $automation = Automation::query()->create([
                'name' => $playbook->name,
                'description' => 'Compiled from playbook #'.$playbook->id,
                'status' => $playbook->is_active
                    ? AutomationStatus::Active
                    : AutomationStatus::Inactive,
                'version' => 1,
                'single_active_run_per_subject' => true,
                'default_guard' => $kind->guard($filters),
                'playbook_id' => $playbook->id,
            ]);

            self::syncNodes($automation, $nodes);
            self::syncEdges($automation, $edges);

            $playbook->update(['automation_id' => $automation->id]);

            AutomationWatchCache::flushAll();

            return $automation->fresh(['nodes', 'edges']) ?? $automation;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function buildGraph(Playbook $playbook, PlaybookKind $kind, array $filters): array
    {
        $trigger = $kind->trigger($filters);
        $nodes = [];
        $edges = [];

        $nodes[] = [
            'node_key' => 'trigger',
            'kind' => 'trigger',
            'type' => $trigger['type'],
            'label' => $trigger['label'],
            'position_x' => 0,
            'position_y' => 0,
            'config' => $trigger['config'],
        ];

        $previousKey = 'trigger';
        $previousOffset = 0;
        $x = 200;

        /** @var list<PlaybookStep> $steps */
        $steps = $playbook->steps->values()->all();

        foreach ($steps as $index => $step) {
            $delta = (int) $step->offset_days - $previousOffset;
            if ($delta < 0) {
                throw ValidationException::withMessages([
                    'steps' => 'Playbook step offsets must be non-decreasing in sort order.',
                ]);
            }

            if ($delta > 0) {
                $waitKey = 'wait_'.$index;
                $nodes[] = [
                    'node_key' => $waitKey,
                    'kind' => 'condition',
                    'type' => 'logic.wait',
                    'label' => "Wait {$delta} day".($delta === 1 ? '' : 's'),
                    'position_x' => $x,
                    'position_y' => 0,
                    'config' => [
                        'mode' => 'relative',
                        'amount' => $delta,
                        'unit' => 'days',
                        'align' => 'send_window',
                    ],
                ];
                $edges[] = [
                    'source_node_id' => $previousKey,
                    'target_node_id' => $waitKey,
                    'source_handle' => 'default',
                    'condition' => ['type' => 'always'],
                ];
                $previousKey = $waitKey;
                $x += 200;
            }

            $actionKey = 'step_'.$index;
            $actionNode = self::actionNode($step, $actionKey, $x);
            $nodes[] = $actionNode;
            $edges[] = [
                'source_node_id' => $previousKey,
                'target_node_id' => $actionKey,
                'source_handle' => 'default',
                'condition' => ['type' => 'always'],
            ];
            $previousKey = $actionKey;
            $previousOffset = (int) $step->offset_days;
            $x += 200;

            // Pairing sugar: send_email|send_sms with "record_notice": "<type>" expands to
            // send → record_notice. A skipped send (no_channel) still records an unsent
            // notice — the attempt to notify is itself the audit fact.
            $noticeType = self::pairedNoticeType($step);
            if ($noticeType !== null) {
                $noticeKey = 'notice_'.$index;
                $nodes[] = [
                    'node_key' => $noticeKey,
                    'kind' => 'action',
                    'type' => 'action.record_notice',
                    'label' => 'Record notice',
                    'position_x' => $x,
                    'position_y' => 0,
                    'config' => [
                        'notice_type' => $noticeType,
                        'sent_from_node_key' => $actionKey,
                    ],
                ];
                $edges[] = [
                    'source_node_id' => $previousKey,
                    'target_node_id' => $noticeKey,
                    'source_handle' => 'default',
                    'condition' => ['type' => 'always'],
                ];
                $previousKey = $noticeKey;
                $x += 200;
            }
        }

        return [$nodes, $edges];
    }

    /**
     * @return array<string, mixed>
     */
    private static function actionNode(PlaybookStep $step, string $nodeKey, int $x): array
    {
        $params = $step->params ?? [];

        return match ($step->action) {
            PlaybookStepAction::SendEmail => [
                'node_key' => $nodeKey,
                'kind' => 'action',
                'type' => 'action.send_email',
                'label' => (string) ($params['label'] ?? 'Send email'),
                'position_x' => $x,
                'position_y' => 0,
                'config' => self::emailConfig($params),
            ],
            PlaybookStepAction::SendSms => [
                'node_key' => $nodeKey,
                'kind' => 'action',
                'type' => 'action.send_sms',
                'label' => (string) ($params['label'] ?? 'Send SMS'),
                'position_x' => $x,
                'position_y' => 0,
                'config' => [
                    'body' => $params['body'] ?? '',
                    'tokens' => (bool) ($params['tokens'] ?? true),
                ],
            ],
            PlaybookStepAction::CreateTask => self::taskNode($params, $nodeKey, $x),
            PlaybookStepAction::RecordNotice => [
                'node_key' => $nodeKey,
                'kind' => 'action',
                'type' => 'action.record_notice',
                'label' => (string) ($params['label'] ?? 'Record notice'),
                'position_x' => $x,
                'position_y' => 0,
                'config' => [
                    'notice_type' => $params['notice_type'] ?? 'payment_reminder',
                ],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function taskNode(array $params, string $nodeKey, int $x): array
    {
        $fields = [
            [
                'property' => 'title',
                'value' => [
                    'kind' => 'static',
                    'value' => (string) ($params['title'] ?? 'Follow-up'),
                ],
            ],
        ];

        if (($params['urgent'] ?? false) === true) {
            $fields[] = [
                'property' => 'priority',
                'value' => [
                    'kind' => 'static',
                    'value' => 'urgent',
                ],
            ];
        }

        return [
            'node_key' => $nodeKey,
            'kind' => 'action',
            'type' => 'action.create_object',
            'label' => (string) ($params['label'] ?? 'Create task'),
            'position_x' => $x,
            'position_y' => 0,
            'config' => [
                'objectType' => 'task',
                'relatedTo' => ['mode' => 'trigger_subject'],
                'fields' => $fields,
            ],
        ];
    }

    private static function pairedNoticeType(PlaybookStep $step): ?string
    {
        if (! in_array($step->action, [PlaybookStepAction::SendEmail, PlaybookStepAction::SendSms], true)) {
            return null;
        }

        $raw = $step->params['record_notice'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function emailConfig(array $params): array
    {
        $templateFamilyId = $params['template_family_id'] ?? $params['email_template_id'] ?? null;
        $hasTemplate = $templateFamilyId !== null;
        $hasInline = isset($params['body']) || (isset($params['subject']) && ! $hasTemplate);

        if ($hasTemplate && isset($params['body'])) {
            throw ValidationException::withMessages([
                'steps' => 'send_email params must be template_family_id XOR inline subject/body.',
            ]);
        }

        if ($hasTemplate) {
            return [
                'subject' => $params['subject'] ?? null,
                'bodyType' => 'template',
                'templateId' => $templateFamilyId,
                'template_family_id' => $templateFamilyId,
            ];
        }

        if (! $hasInline) {
            // Defaults for seed/reference playbooks without explicit params.
            return [
                'subject' => ['kind' => 'static', 'value' => 'Overdue balance'],
                'bodyType' => 'custom',
                'body' => ['kind' => 'static', 'value' => 'Please pay your balance.'],
            ];
        }

        return [
            'subject' => $params['subject'] ?? ['kind' => 'static', 'value' => 'Overdue balance'],
            'bodyType' => 'custom',
            'body' => $params['body'] ?? ['kind' => 'static', 'value' => 'Please pay your balance.'],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $nodes */
    private static function syncNodes(Automation $automation, array $nodes): void
    {
        $automation->nodes()->delete();

        if ($nodes === []) {
            return;
        }

        $records = array_map(fn (array $node) => [
            'automation_id' => $automation->id,
            'node_key' => $node['node_key'] ?? uniqid('node_'),
            'kind' => $node['kind'],
            'type' => $node['type'],
            'label' => $node['label'] ?? ($node['node_key'] ?? 'node'),
            'description' => $node['description'] ?? null,
            'position_x' => (int) ($node['position_x'] ?? 0),
            'position_y' => (int) ($node['position_y'] ?? 0),
            'config' => json_encode($node['config'] ?? []),
            'metadata' => isset($node['metadata']) ? json_encode($node['metadata']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $nodes);

        AutomationNode::insert($records);
    }

    /** @param  array<int, array<string, mixed>>  $edges */
    private static function syncEdges(Automation $automation, array $edges): void
    {
        $automation->edges()->delete();

        if ($edges === []) {
            return;
        }

        $nodeMap = $automation->nodes()
            ->pluck('id', 'node_key')
            ->all();

        $records = [];
        foreach ($edges as $edge) {
            $sourceId = $nodeMap[$edge['source_node_id']] ?? null;
            $targetId = $nodeMap[$edge['target_node_id']] ?? null;

            if ($sourceId === null || $targetId === null) {
                continue;
            }

            $records[] = [
                'automation_id' => $automation->id,
                'source_node_id' => $sourceId,
                'target_node_id' => $targetId,
                'source_handle' => $edge['source_handle'] ?? 'default',
                'target_handle' => $edge['target_handle'] ?? null,
                'label' => $edge['label'] ?? null,
                'condition' => json_encode($edge['condition'] ?? ['type' => 'always']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($records !== []) {
            AutomationEdge::insert($records);
        }
    }
}
