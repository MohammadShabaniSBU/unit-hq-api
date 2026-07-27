<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Enums\ContactChannelType;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\Contact;
use App\Models\Employee;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\CreateObjectAllowlist;
use App\Support\Automation\RunContext;
use App\Support\Automation\TokenResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateObjectHandler implements NodeHandler
{
    /** @var list<string> */
    private const PHONE_FIELD_KEYS = ['phone', 'phone_number'];

    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        $config = $node->config ?? [];
        $objectType = (string) ($config['objectType'] ?? $config['object_type'] ?? '');
        $fields = $config['fields'] ?? $config['updates'] ?? [];

        if ($objectType === '') {
            throw new RuntimeException('create_object missing objectType');
        }

        if (! CreateObjectAllowlist::contains($objectType)) {
            throw new RuntimeException(
                "create_object objectType \"{$objectType}\" is not supported. Allowed: ".CreateObjectAllowlist::supportedList(),
            );
        }

        $class = Relation::getMorphedModel($objectType);
        if ($class === null || ! is_a($class, Model::class, true)) {
            throw new RuntimeException("create_object unknown morph type {$objectType}");
        }

        /** @var array<string, mixed> $resolved */
        $resolved = [];
        $phoneValue = null;

        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $property = (string) ($field['property'] ?? '');
                if ($property === '') {
                    continue;
                }

                $value = TokenResolver::resolveValueSource($field['value'] ?? null, $context);

                if (in_array($property, self::PHONE_FIELD_KEYS, true)) {
                    if ($objectType === 'contact' && $value !== null && $value !== '') {
                        $phoneValue = is_string($value) ? $value : (string) $value;
                    }

                    continue;
                }

                $resolved[$property] = $value;
            }
        }

        /** @var Model $prototype */
        $prototype = new $class;
        $fillable = $prototype->getFillable();
        $attributes = array_intersect_key($resolved, array_flip($fillable));
        $attributes = $this->applyRelatedTo($objectType, $attributes, $config, $run, $context);
        $attributes = $this->prepareAttributes($objectType, $attributes, $run);
        $this->assertRequired($objectType, $attributes);

        /** @var Model $model */
        $model = AutomationContext::run((int) $run->id, function () use ($class, $attributes, $objectType, $phoneValue) {
            return DB::transaction(function () use ($class, $attributes, $objectType, $phoneValue) {
                /** @var Model $created */
                $created = $class::query()->create($attributes);

                if ($objectType === 'contact' && $phoneValue !== null && $created instanceof Contact) {
                    $this->createPrimaryPhoneChannel($created, $phoneValue);
                }

                return $created;
            });
        });

        $written = [];
        foreach (array_keys($attributes) as $key) {
            $written[$key] = $model->getAttribute($key);
        }
        if ($phoneValue !== null) {
            $written['phone'] = $phoneValue;
        }

        return [
            'object_type' => $objectType,
            'subject_id' => $model->getKey(),
            'fields' => $written,
        ];
    }

    /**
     * Resolve relatedTo (TargetRecord) into taskable_* / notable_* when not already set.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyRelatedTo(
        string $objectType,
        array $attributes,
        array $config,
        AutomationRun $run,
        RunContext $context,
    ): array {
        if ($objectType !== 'task' && $objectType !== 'note') {
            return $attributes;
        }

        $typeKey = $objectType === 'task' ? 'taskable_type' : 'notable_type';
        $idKey = $objectType === 'task' ? 'taskable_id' : 'notable_id';

        $hasType = ($attributes[$typeKey] ?? null) !== null && $attributes[$typeKey] !== '';
        $hasId = ($attributes[$idKey] ?? null) !== null && $attributes[$idKey] !== '';
        if ($hasType && $hasId) {
            return $attributes;
        }

        $related = $config['relatedTo'] ?? $config['related_to'] ?? ['mode' => 'trigger_subject'];
        if (! is_array($related)) {
            $related = ['mode' => 'trigger_subject'];
        }

        $normalized = TokenResolver::normalizeTargetRecordConfig(['targetRecord' => $related]);
        $id = TokenResolver::resolveTargetRecord($normalized, $context);
        $mode = (string) ($normalized['mode'] ?? 'trigger_subject');

        $type = match ($mode) {
            'trigger_subject' => $this->normalizeMorphAlias($run->subject_type),
            'static' => $this->normalizeMorphAlias($normalized['objectType'] ?? null),
            'step_output' => $this->normalizeMorphAlias(
                $context->get('steps.'.($normalized['nodeKey'] ?? '').'.object_type'),
            ),
            'expression' => $this->normalizeMorphAlias(
                $normalized['objectType'] ?? $run->subject_type,
            ),
            default => $this->normalizeMorphAlias($run->subject_type),
        };

        if (! $hasType && $type !== null && $type !== '') {
            $attributes[$typeKey] = $type;
        }
        if (! $hasId && $id !== null && $id !== '') {
            $attributes[$idKey] = $id;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepareAttributes(string $objectType, array $attributes, AutomationRun $run): array
    {
        if ($objectType === 'task') {
            if (array_key_exists('taskable_type', $attributes)) {
                $attributes['taskable_type'] = $this->normalizeMorphAlias($attributes['taskable_type']);
            }
            $attributes['priority'] = $attributes['priority'] ?? 'medium';
            $attributes['status'] = $attributes['status'] ?? 'open';
            $attributes['created_by'] = $this->resolveEmployeeId($run, $attributes['created_by'] ?? null);
        }

        if ($objectType === 'note') {
            if (array_key_exists('notable_type', $attributes)) {
                $attributes['notable_type'] = $this->normalizeMorphAlias($attributes['notable_type']);
            }
            $attributes['employee_id'] = $this->resolveEmployeeId($run, $attributes['employee_id'] ?? null);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertRequired(string $objectType, array $attributes): void
    {
        if ($objectType === 'contact') {
            foreach (['first_name', 'last_name'] as $key) {
                $value = $attributes[$key] ?? null;
                if ($value === null || $value === '') {
                    throw new RuntimeException("create_object contact requires {$key}");
                }
            }

            return;
        }

        if ($objectType === 'deal') {
            $contactId = $attributes['contact_id'] ?? null;
            if ($contactId === null || $contactId === '') {
                throw new RuntimeException('create_object deal requires contact_id');
            }

            return;
        }

        if ($objectType === 'task') {
            $title = $attributes['title'] ?? null;
            if ($title === null || $title === '') {
                throw new RuntimeException('create_object task requires title');
            }
            $this->assertMorphParent(
                (string) ($attributes['taskable_type'] ?? ''),
                $attributes['taskable_id'] ?? null,
                'taskable',
            );

            return;
        }

        if ($objectType === 'note') {
            $content = $attributes['content'] ?? null;
            if ($content === null || $content === '') {
                throw new RuntimeException('create_object note requires content');
            }
            $this->assertMorphParent(
                (string) ($attributes['notable_type'] ?? ''),
                $attributes['notable_id'] ?? null,
                'notable',
            );
        }
    }

    private function assertMorphParent(string $type, mixed $id, string $label): void
    {
        if ($type === '' || $id === null || $id === '') {
            throw new RuntimeException("create_object requires {$label}_type and {$label}_id");
        }

        $class = Relation::getMorphedModel($type);
        if ($class === null || ! is_a($class, Model::class, true)) {
            throw new RuntimeException("create_object unknown {$label}_type \"{$type}\"");
        }

        if (! $class::query()->whereKey($id)->exists()) {
            throw new RuntimeException("create_object {$label} {$type}#{$id} does not exist");
        }
    }

    private function normalizeMorphAlias(mixed $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $type = (string) $type;
        if (Relation::getMorphedModel($type) !== null) {
            return $type;
        }

        foreach (Relation::morphMap() as $alias => $class) {
            if ($class === $type) {
                return (string) $alias;
            }
        }

        return $type;
    }

    private function resolveEmployeeId(AutomationRun $run, mixed $provided): int
    {
        if ($provided !== null && $provided !== '') {
            return (int) $provided;
        }

        if ($run->causer_id !== null && $this->causerIsEmployee($run)) {
            return (int) $run->causer_id;
        }

        $employeeId = Employee::query()->value('id');
        if ($employeeId === null) {
            throw new RuntimeException('create_object could not resolve an employee for attribution');
        }

        return (int) $employeeId;
    }

    private function causerIsEmployee(AutomationRun $run): bool
    {
        $type = $run->causer_type;
        if ($type === null) {
            return false;
        }

        if ($type === Employee::class || $type === 'employee') {
            return true;
        }

        $class = Relation::getMorphedModel($type);

        return $class === Employee::class;
    }

    private function createPrimaryPhoneChannel(Contact $contact, string $phone): void
    {
        $hasPrimaryPhone = $contact->channels()
            ->where('type', ContactChannelType::Phone)
            ->where('is_primary', true)
            ->exists();

        $contact->channels()->create([
            'type' => ContactChannelType::Phone,
            'value' => $phone,
            'is_primary' => ! $hasPrimaryPhone,
        ]);
    }
}
