<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

final class UpdateObjectHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');
        $updates = $config['updates'] ?? [];

        if ($objectType === '') {
            throw new RuntimeException('update_object missing objectType');
        }

        $targetRecord = TokenResolver::normalizeTargetRecordConfig($config);
        $id = TokenResolver::resolveTargetRecord($targetRecord, $context);

        $model = $this->findModel($objectType, $id);

        if ($model === null) {
            throw new RuntimeException("update_object could not resolve target for {$objectType}");
        }

        /** @var array<string, mixed> $attributes */
        $attributes = [];
        $old = [];

        if (is_array($updates)) {
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                if ($property === '') {
                    continue;
                }
                $old[$property] = $model->getAttribute($property);
                $attributes[$property] = TokenResolver::resolveValueSource($update['value'] ?? null, $context);
            }
        }

        if ($attributes !== []) {
            AutomationContext::run((int) $run->id, function () use ($model, $attributes): void {
                $model->update($attributes);
            });
        }

        $new = [];
        foreach (array_keys($attributes) as $key) {
            $new[$key] = $model->getAttribute($key);
        }

        return [
            'object_type' => $objectType,
            'subject_id' => $model->getKey(),
            'old' => $old,
            'new' => $new,
        ];
    }

    private function findModel(string $objectType, mixed $id): ?Model
    {
        $class = Relation::getMorphedModel($objectType);
        if ($class === null || ! is_a($class, Model::class, true)) {
            return null;
        }

        if ($id === null || $id === '') {
            return null;
        }

        return $class::query()->find($id);
    }
}
