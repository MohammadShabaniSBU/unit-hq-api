<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Enums\AttributeEntityType;
use App\Models\AttributeDefinition;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Unit;
use App\Support\Attributes\AttributeValueUpserter;
use App\Support\Auth\SubjectSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetCustomProperty implements Tool, Approvable
{
    use InteractsWithApprovals;

    private const TYPE_MAP = [
        'contact' => Contact::class,
        'deal' => Deal::class,
        'offer' => Offer::class,
        'reservation' => Reservation::class,
        'unit' => Unit::class,
        'contract' => Contract::class,
    ];

    public function __construct(private readonly Employee $employee) {}

    public function description(): Stringable|string
    {
        return 'Set (or clear, by passing an empty value) a custom attribute value on a contact, deal, offer, reservation, unit, or contract.';
    }

    public function handle(Request $request): Stringable|string
    {
        $type = $request['entity_type'] ?? null;

        if (! isset(self::TYPE_MAP[$type])) {
            return json_encode([
                'success' => false,
                'error' => "Unsupported entity_type '{$type}'.",
            ]);
        }

        $entityType = AttributeEntityType::from($type);
        $modelClass = self::TYPE_MAP[$type];
        $entity = $modelClass::query()->find($request['entity_id']);

        if ($entity === null) {
            return json_encode([
                'success' => false,
                'error' => "No {$type} found with that ID.",
            ]);
        }

        if (! $this->employee->allowsPermission($entityType->managePermission(), SubjectSite::for($entity))) {
            return json_encode([
                'success' => false,
                'error' => "You do not have permission to set custom properties on this {$type}.",
            ]);
        }

        $definition = AttributeDefinition::query()->with('options')->find($request['definition_id']);

        if ($definition === null) {
            return json_encode([
                'success' => false,
                'error' => 'No attribute definition found with that ID.',
            ]);
        }

        if ($definition->entity_type !== $entityType) {
            return json_encode([
                'success' => false,
                'error' => 'Attribute definition entity type mismatch.',
            ]);
        }

        try {
            $result = AttributeValueUpserter::upsert(
                $definition,
                $entity,
                $entityType,
                $request['value'] ?? null,
                $this->employee,
            );
        } catch (ValidationException $exception) {
            return json_encode([
                'success' => false,
                'error' => implode(' ', $exception->validator->errors()->all()),
            ]);
        }

        return json_encode([
            'success' => true,
            'message' => $result === null ? 'Attribute value cleared successfully.' : 'Attribute value saved successfully.',
            'entity_type' => $type,
            'entity_id' => $entity->id,
            'definition_id' => $definition->id,
            'key' => $definition->key,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()
                ->description('Type of record the custom attribute belongs to')
                ->enum(array_keys(self::TYPE_MAP))
                ->required(),
            'entity_id' => $schema->integer()
                ->description('ID of the record the custom attribute belongs to')
                ->required(),
            'definition_id' => $schema->integer()
                ->description('ID of the attribute definition to set a value for')
                ->required(),
            'value' => $schema->union([
                $schema->string(),
                $schema->number(),
                $schema->boolean(),
                $schema->array()->items($schema->integer()),
            ])
                ->description('The value to set. Use a string for text/date, a number, a boolean, an option ID for select attributes, or an array of option IDs for multiselect attributes. Omit or pass an empty value to clear it.')
                ->nullable(),
        ];
    }
}
