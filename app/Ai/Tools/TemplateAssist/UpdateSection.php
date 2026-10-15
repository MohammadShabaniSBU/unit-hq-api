<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class UpdateSection extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Merge params onto a section. Omitted keys stay as they are.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $sectionId = $this->stringValue($request, 'section_id');
        $params = $this->recordValue($request, 'params');
        if ($sectionId === null || $params === null) {
            return $this->missing('section_id', 'section_id and params are required.');
        }

        return $this->encode($this->workspace()->apply([
            'op' => 'update_section',
            'section_id' => $sectionId,
            'params' => $params,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'section_id' => $schema->string()
                ->description('Section id.')
                ->required(),
            'params' => $schema->object([
                'background_color' => $schema->string()->nullable(),
                'padding' => $schema->string()->enum(['none', 'sm', 'md', 'lg']),
                'stack_on_mobile' => $schema->boolean(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    protected function fields(): array
    {
        return ['section_id', 'params'];
    }
}
