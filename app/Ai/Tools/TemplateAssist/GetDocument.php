<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class GetDocument extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Read the working email document. Returns section, column, and block ids, layouts, types, and short text previews.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        return $this->encode($this->workspace()->readDocument());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function fields(): array
    {
        return [];
    }
}
