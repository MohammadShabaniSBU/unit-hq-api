<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use App\Support\Ai\Tools\AgentTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

/**
 * Schema-only SDK tool. handle() must never run — AgentRuntime owns the loop.
 */
final class SchemaOnlySdkTool implements Tool
{
    public function __construct(
        private readonly AgentTool $tool,
        private readonly string $wireName,
    ) {}

    public function name(): string
    {
        return $this->wireName;
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    public function handle(Request $request): string
    {
        throw new LogicException(
            'Schema-only SDK tool handle() must not run. AgentRuntime owns the loop.',
        );
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        foreach ($this->tool->schema() as $name => $rules) {
            $field = match ($rules['type'] ?? 'string') {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                'array' => $schema->array(),
                default => $schema->string(),
            };

            if (isset($rules['description'])) {
                $field->description($rules['description']);
            }
            if (isset($rules['enum'])) {
                $field->enum($rules['enum']);
            }
            if ($rules['required'] ?? false) {
                $field->required();
            }

            $fields[$name] = $field;
        }

        return $fields;
    }
}
