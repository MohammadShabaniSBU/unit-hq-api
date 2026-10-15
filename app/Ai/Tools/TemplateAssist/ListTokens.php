<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class ListTokens extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'List tokens available for this template. Use only these paths, with {{path}} syntax.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        return $this->encode($this->workspace()->readTokens());
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
