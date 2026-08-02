<?php

declare(strict_types=1);

namespace App\Support\Communications;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Closed-set v1 email block document validator.
 * Unknown types/versions are rejected — forward-compat by version bump only.
 */
final class EmailBlockDocument
{
    public const VERSION = 1;

    /** @var list<string> */
    public const TYPES = [
        'heading',
        'paragraph',
        'button',
        'image',
        'divider',
        'spacer',
        'unit_summary',
        'raw_html',
    ];

    /**
     * @param  mixed  $doc
     * @return array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $doc): array
    {
        if (! is_array($doc)) {
            throw ValidationException::withMessages([
                'blocks' => [__('errors.templates.blocks_invalid')],
            ]);
        }

        $version = $doc['version'] ?? null;
        if ($version !== self::VERSION && $version !== (string) self::VERSION) {
            throw ValidationException::withMessages([
                'blocks.version' => [__('errors.templates.blocks_unknown_version', ['version' => (string) $version])],
            ]);
        }

        $blocks = $doc['blocks'] ?? null;
        if (! is_array($blocks)) {
            throw ValidationException::withMessages([
                'blocks.blocks' => [__('errors.templates.blocks_invalid')],
            ]);
        }

        $normalized = [];
        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}" => [__('errors.templates.blocks_invalid')],
                ]);
            }

            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}.type" => [__('errors.templates.blocks_unknown_type', ['type' => $type])],
                ]);
            }

            $params = is_array($block['params'] ?? null) ? $block['params'] : [];
            $id = (string) ($block['id'] ?? '');
            if ($id === '') {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}.id" => [__('errors.templates.blocks_invalid')],
                ]);
            }

            $params = self::validateParams($type, $params, $index);
            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'params' => $params,
            ];
        }

        return [
            'version' => self::VERSION,
            'blocks' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function validateParams(string $type, array $params, int $index): array
    {
        $rules = match ($type) {
            'heading' => [
                'text' => ['required', 'string'],
                'level' => ['required', 'integer', 'in:1,2'],
            ],
            'paragraph' => [
                'html' => ['required', 'string'],
            ],
            'button' => [
                'label' => ['required', 'string', 'max:255'],
                'url' => ['required', 'string', 'max:2000'],
                'style' => ['required', 'string', 'in:primary,outline'],
            ],
            'image' => [
                'asset_id' => ['sometimes', 'nullable', 'integer'],
                'url' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
                'width_percent' => ['required', 'integer', 'min:1', 'max:100'],
            ],
            'divider' => [],
            'spacer' => [
                'height' => ['required', 'integer', 'min:4', 'max:200'],
            ],
            'unit_summary' => [],
            'raw_html' => [
                'html' => ['required', 'string'],
            ],
            default => [],
        };

        if ($rules === []) {
            return [];
        }

        $validator = Validator::make($params, $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                $messages["blocks.blocks.{$index}.params.{$field}"] = $fieldMessages;
            }
            throw ValidationException::withMessages($messages);
        }

        $validated = $validator->validated();

        if ($type === 'image') {
            $assetId = $validated['asset_id'] ?? null;
            $url = $validated['url'] ?? null;
            if (($assetId === null || $assetId === '') && ($url === null || $url === '')) {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}.params" => [__('errors.templates.image_ref_required')],
                ]);
            }
        }

        return $validated;
    }
}
