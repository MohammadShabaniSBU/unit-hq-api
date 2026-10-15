<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class RemoveNode extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Remove a section (and its contents) or a block by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $id = $this->stringValue($request, 'id');
        if ($id === null || $id === '') {
            return $this->missing('id', 'id is required.');
        }

        return $this->encode($this->workspace()->removeNode($id));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Section id or block id.')
                ->required(),
        ];
    }

    protected function fields(): array
    {
        return ['id'];
    }
}
