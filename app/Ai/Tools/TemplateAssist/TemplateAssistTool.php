<?php

declare(strict_types=1);

namespace App\Ai\Tools\TemplateAssist;

use App\Support\Templates\Assist\DocumentWorkspace;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

abstract class TemplateAssistTool implements Tool
{
    public function __construct(protected readonly int $turnId) {}

    /**
     * @return list<string>
     */
    abstract protected function fields(): array;

    protected function workspace(): DocumentWorkspace
    {
        return DocumentWorkspace::forTurn($this->turnId);
    }

    protected function unknown(Request $request): ?string
    {
        $extra = array_diff(array_keys($request->all()), $this->fields());
        if ($extra === []) {
            return null;
        }

        $field = (string) array_values($extra)[0];

        return $this->encode(DocumentWorkspace::failure(
            'assist.unknown_field',
            $field,
            'Unknown field.',
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function encode(array $result): string
    {
        return json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    protected function missing(string $path, string $hint): string
    {
        return $this->encode(DocumentWorkspace::failure('assist.document_invalid', $path, $hint));
    }

    protected function stringValue(Request $request, string $key): ?string
    {
        $value = $request[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    protected function intValue(Request $request, string $key): ?int
    {
        $value = $request[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function recordValue(Request $request, string $key): ?array
    {
        $value = $request[$key] ?? null;
        if (! is_array($value)) {
            return null;
        }
        if ($value === []) {
            return [];
        }
        if (array_is_list($value)) {
            return null;
        }

        return $value;
    }
}
