<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class MoveBlock extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Move a block to another column and index.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $blockId = $this->stringValue($request, 'block_id');
        $columnId = $this->stringValue($request, 'to_column_id');
        $index = $this->intValue($request, 'to_index');
        if ($blockId === null || $columnId === null || $index === null) {
            return $this->missing('block_id', 'block_id, to_column_id, and to_index are required.');
        }

        return $this->encode($this->workspace()->apply([
            'op' => 'move_block',
            'block_id' => $blockId,
            'to_column_id' => $columnId,
            'to_index' => $index,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema->string()->description('Block id.')->required(),
            'to_column_id' => $schema->string()->description('Destination column id.')->required(),
            'to_index' => $schema->integer()->description('Zero-based index in the destination column.')->required(),
        ];
    }

    protected function fields(): array
    {
        return ['block_id', 'to_column_id', 'to_index'];
    }
}
