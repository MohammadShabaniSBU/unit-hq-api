<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use App\Support\Communications\EmailBlockDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class SetSectionLayout extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Change a section layout. Shrinking keeps the left columns and appends the dropped blocks onto the last kept column.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $sectionId = $this->stringValue($request, 'section_id');
        $layout = $this->stringValue($request, 'layout');
        if ($sectionId === null || $layout === null) {
            return $this->missing('section_id', 'section_id and layout are required.');
        }

        return $this->encode($this->workspace()->apply([
            'op' => 'set_section_layout',
            'section_id' => $sectionId,
            'layout' => $layout,
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'section_id' => $schema->string()
                ->description('Section id.')
                ->required(),
            'layout' => $schema->string()
                ->description('Column layout preset.')
                ->enum(array_keys(EmailBlockDocument::LAYOUTS))
                ->required(),
        ];
    }

    protected function fields(): array
    {
        return ['section_id', 'layout'];
    }
}
