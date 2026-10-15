<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class MoveSection extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Move a section to a new index.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $sectionId = $this->stringValue($request, 'section_id');
        $index = $this->intValue($request, 'to_index');
        if ($sectionId === null || $index === null) {
            return $this->missing('section_id', 'section_id and to_index are required.');
        }

        return $this->encode($this->workspace()->apply([
            'op' => 'move_section',
            'section_id' => $sectionId,
            'to_index' => $index,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'section_id' => $schema->string()->description('Section id.')->required(),
            'to_index' => $schema->integer()->description('Zero-based destination index.')->required(),
        ];
    }

    protected function fields(): array
    {
        return ['section_id', 'to_index'];
    }
}
