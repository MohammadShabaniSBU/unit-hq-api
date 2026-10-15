<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class UpdateBlock extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Merge params onto a block, then validate the document.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $blockId = $this->stringValue($request, 'block_id');
        $params = $this->recordValue($request, 'params');
        if ($blockId === null || $params === null) {
            return $this->missing('block_id', 'block_id and params are required.');
        }

        return $this->encode($this->workspace()->apply([
            'op' => 'update_block',
            'block_id' => $blockId,
            'params' => $params,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema->string()
                ->description('Block id.')
                ->required(),
            'params' => $schema->object([])
                ->description('Partial params to merge onto the block.')
                ->required(),
        ];
    }

    protected function fields(): array
    {
        return ['block_id', 'params'];
    }
}
