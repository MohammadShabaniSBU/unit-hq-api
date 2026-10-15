<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use App\Support\Communications\EmailBlockDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

final class AddSection extends TemplateAssistTool
{
    public function description(): Stringable|string
    {
        return 'Add an empty section at an index. Columns match the layout. Ids are assigned by the server.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->unknown($request)) {
            return $error;
        }

        $index = $this->intValue($request, 'index');
        $layout = $this->stringValue($request, 'layout');
        if ($index === null || $layout === null) {
            return $this->missing('index', 'index and layout are required.');
        }

        $params = [];
        if (array_key_exists('params', $request->all())) {
            $params = $this->recordValue($request, 'params');
            if ($params === null) {
                return $this->missing('params', 'params must be an object.');
            }
        }

        return $this->encode($this->workspace()->addSection($index, $layout, $params));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'index' => $schema->integer()
                ->description('Zero-based index among sections.')
                ->required(),
            'layout' => $schema->string()
                ->description('Column layout preset.')
                ->enum(array_keys(EmailBlockDocument::LAYOUTS))
                ->required(),
            'params' => $schema->object([
                'background_color' => $schema->string()->nullable(),
                'padding' => $schema->string()->enum(['none', 'sm', 'md', 'lg']),
                'stack_on_mobile' => $schema->boolean(),
            ])->withoutAdditionalProperties()->nullable(),
        ];
    }

    protected function fields(): array
    {
        return ['index', 'layout', 'params'];
    }
}
