<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class InsertBlocks extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Insert blocks into a column. Pass type and params only. The server assigns block ids.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $columnId = $this->stringValue($request, 'column_id');
        $index = $this->intValue($request, 'index');
        $blocks = $request['blocks'] ?? null;
        if ($columnId === null || $index === null || ! is_array($blocks) || ! array_is_list($blocks)) {
            return $this->missing('blocks', 'column_id, index, and blocks are required.');
        }

        /** @var list<array<string, mixed>> $clean */
        $clean = [];
        foreach ($blocks as $position => $block) {
            if (! is_array($block)) {
                return $this->missing("blocks.{$position}", 'Each block needs a type and params.');
            }
            $clean[] = $block;
        }

        return $this->encode($this->workspace()->insertBlocks($columnId, $index, $clean));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'column_id' => $schema->string()
                ->description('Column id.')
                ->required(),
            'index' => $schema->integer()
                ->description('Zero-based index among blocks in the column.')
                ->required(),
            'blocks' => $schema->array()
                ->items($schema->object([
                    'type' => $schema->string()->description('Block type.')->required(),
                    'params' => $schema->object([])->description('Block params. Do not include an id.')->required(),
                ])->withoutAdditionalProperties())
                ->min(1)
                ->required(),
        ];
    }

    protected function fields(): array
    {
        return ['column_id', 'index', 'blocks'];
    }
}
