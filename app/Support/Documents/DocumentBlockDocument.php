<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\TemplatePurpose;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Closed-set v1 document block validator for channel=document families.
 * Unknown types/versions are rejected — forward-compat by version bump only.
 */
final class DocumentBlockDocument
{
    public const VERSION = 1;

    /** @var list<string> */
    public const TYPES = [
        'heading',
        'paragraph',
        'divider',
        'spacer',
        'legal_section',
        'parties',
        'terms_table',
        'signature_anchor',
        'page_break',
    ];

    /**
     * @param  mixed  $doc
     * @return array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}
     *
     * @throws ValidationException
     */
    public static function validate(mixed $doc, ?TemplatePurpose $purpose = null): array
    {
        if (! is_array($doc)) {
            throw ValidationException::withMessages([
                'blocks' => [__('errors.documents.blocks_invalid')],
            ]);
        }

        $version = $doc['version'] ?? null;
        if ($version !== self::VERSION && $version !== (string) self::VERSION) {
            throw ValidationException::withMessages([
                'blocks.version' => [__('errors.documents.blocks_unknown_version', ['version' => (string) $version])],
            ]);
        }

        $blocks = $doc['blocks'] ?? null;
        if (! is_array($blocks)) {
            throw ValidationException::withMessages([
                'blocks.blocks' => [__('errors.documents.blocks_invalid')],
            ]);
        }

        $normalized = [];
        $typeCounts = [];
        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}" => [__('errors.documents.blocks_invalid')],
                ]);
            }

            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}.type" => [__('errors.documents.blocks_unknown_type', ['type' => $type])],
                ]);
            }

            $params = is_array($block['params'] ?? null) ? $block['params'] : [];
            $id = (string) ($block['id'] ?? '');
            if ($id === '') {
                throw ValidationException::withMessages([
                    "blocks.blocks.{$index}.id" => [__('errors.documents.blocks_invalid')],
                ]);
            }

            $params = self::validateParams($type, $params, $index);
            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'params' => $params,
            ];
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        return [
            'version' => self::VERSION,
            'blocks' => $normalized,
        ];
    }

    /**
     * Strict validation for render/generate — empty docs are rejected; purpose rules apply.
     *
     * @param  mixed  $doc
     * @return array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}
     */
    public static function validateForRender(mixed $doc, ?TemplatePurpose $purpose = null): array
    {
        $validated = self::validate($doc, $purpose);
        if ($validated['blocks'] === []) {
            throw ValidationException::withMessages([
                'blocks' => [__('errors.documents.blocks_invalid')],
            ]);
        }

        $typeCounts = [];
        foreach ($validated['blocks'] as $block) {
            $type = $block['type'];
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }
        self::assertPurposeRules($purpose, $typeCounts);

        return $validated;
    }

    /**
     * @param  array<string, int>  $typeCounts
     */
    public static function assertPurposeRules(?TemplatePurpose $purpose, array $typeCounts): void
    {
        $anchorCount = $typeCounts['signature_anchor'] ?? 0;
        if ($anchorCount !== 1) {
            throw ValidationException::withMessages([
                'blocks' => [__('errors.documents.signature_anchor_required')],
            ]);
        }

        if ($purpose === TemplatePurpose::Contract) {
            if (($typeCounts['parties'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'blocks' => [__('errors.documents.parties_required')],
                ]);
            }
            if (($typeCounts['terms_table'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'blocks' => [__('errors.documents.terms_table_required')],
                ]);
            }
        }
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
            'divider' => [],
            'spacer' => [
                'height' => ['required', 'integer', 'min:4', 'max:200'],
            ],
            'legal_section' => [
                'heading' => ['required', 'string', 'max:500'],
                'body' => ['required', 'string'],
            ],
            'parties', 'terms_table', 'signature_anchor', 'page_break' => [],
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

        return $validator->validated();
    }
}
