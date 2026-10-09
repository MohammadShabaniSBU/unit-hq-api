<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationNodeType;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\TemplateFamily;
use Illuminate\Validation\ValidationException;

/**
 * Design-time validation for trigger configs: billing field whitelist + payment create-only.
 */
final class TriggerConfigValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function assertValid(array $nodes): void
    {
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if (! in_array($type, [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::ObjectUpdated->value,
            ], true)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $nodeKey = (string) ($node['node_key'] ?? $node['id'] ?? '');
            $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');

            if ($objectType === '') {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: trigger requires objectType.",
                ]);
            }

            if ($type === AutomationNodeType::ObjectUpdated->value && $objectType === 'payment') {
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: payment supports object_created only (payments are append-only).",
                ]);
            }

            if (! TriggerableFields::supports($objectType)) {
                continue;
            }

            if ($type === AutomationNodeType::ObjectCreated->value) {
                self::assertFilterFields($config['filters'] ?? null, $objectType, $nodeKey);
            }

            if ($type === AutomationNodeType::ObjectUpdated->value) {
                $property = (string) ($config['property'] ?? '');
                if ($property !== '' && TriggerableFields::find($objectType, $property) === null) {
                    throw ValidationException::withMessages([
                        'nodes' => "Node {$nodeKey}: unknown trigger field [{$property}] for [{$objectType}].",
                    ]);
                }
            }
        }

        $triggerObjectType = self::triggerObjectType($nodes);

        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if ($type !== AutomationNodeType::Branch->value) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $nodeKey = (string) ($node['node_key'] ?? $node['id'] ?? '');
            $arms = $config['arms'] ?? null;

            if (is_array($arms) && $arms !== []) {
                $seen = [];
                foreach ($arms as $arm) {
                    if (! is_array($arm)) {
                        continue;
                    }

                    $armId = (string) ($arm['id'] ?? '');
                    if ($armId === '') {
                        throw ValidationException::withMessages([
                            'nodes' => "Node {$nodeKey}: branch arm requires an id.",
                        ]);
                    }
                    if (isset($seen[$armId])) {
                        throw ValidationException::withMessages([
                            'nodes' => "Node {$nodeKey}: duplicate branch arm id [{$armId}].",
                        ]);
                    }
                    $seen[$armId] = true;

                    if ($triggerObjectType !== '' && TriggerableFields::supports($triggerObjectType)) {
                        self::assertFilterFields($arm['filters'] ?? null, $triggerObjectType, $nodeKey);
                    }
                }

                continue;
            }

            if ($triggerObjectType !== '' && TriggerableFields::supports($triggerObjectType)) {
                self::assertFilterFields($config['filters'] ?? $config['condition'] ?? null, $triggerObjectType, $nodeKey);
            }
        }
    }

    public static function assertAutomation(Automation $automation): void
    {
        $nodes = $automation->nodes->map(fn (AutomationNode $n) => [
            'node_key' => $n->node_key,
            'type' => $n->type instanceof AutomationNodeType ? $n->type->value : (string) $n->type,
            'config' => $n->config ?? [],
        ])->all();

        self::assertValid($nodes);
        self::assertSendableTemplates($nodes);
    }

    /**
     * Live send_email / send_sms nodes must point at a published template family.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function assertSendableTemplates(array $nodes): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            $type = $type instanceof AutomationNodeType ? $type->value : (string) $type;
            if (! in_array($type, [
                AutomationNodeType::SendEmail->value,
                AutomationNodeType::SendSms->value,
            ], true)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $templateId = self::templateFamilyId($config);
            if ($templateId === null) {
                continue;
            }

            $family = TemplateFamily::query()->find($templateId);
            if ($family === null || $family->currentVersion === null) {
                $nodeKey = (string) ($node['node_key'] ?? $node['id'] ?? '');
                throw ValidationException::withMessages([
                    'nodes' => "Node {$nodeKey}: template family [{$templateId}] has no published version.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function templateFamilyId(array $config): ?int
    {
        $raw = $config['template_family_id']
            ?? $config['templateId']
            ?? $config['template_id']
            ?? $config['email_template_id']
            ?? null;

        if (is_int($raw)) {
            return $raw;
        }

        if ((is_string($raw) || is_float($raw)) && is_numeric($raw)) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private static function triggerObjectType(array $nodes): string
    {
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            if (! in_array($type, [
                AutomationNodeType::ObjectCreated->value,
                AutomationNodeType::ObjectUpdated->value,
            ], true)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            return (string) ($config['objectType'] ?? $config['object_type'] ?? '');
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    private static function assertFilterFields(mixed $filters, string $objectType, string $nodeKey): void
    {
        if (! is_array($filters)) {
            return;
        }

        self::walkFilterTree($filters, $objectType, $nodeKey);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function walkFilterTree(array $node, string $objectType, string $nodeKey): void
    {
        if (isset($node['conditions']) && is_array($node['conditions'])) {
            foreach ($node['conditions'] as $child) {
                if (is_array($child)) {
                    self::walkFilterTree($child, $objectType, $nodeKey);
                }
            }

            return;
        }

        $field = (string) ($node['field'] ?? '');
        if ($field === '') {
            return;
        }

        if (TriggerableFields::find($objectType, $field) === null) {
            throw ValidationException::withMessages([
                'nodes' => "Node {$nodeKey}: unknown trigger field [{$field}] for [{$objectType}].",
            ]);
        }
    }
}
