<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Support\Templates\TokenCatalog;
use App\Support\Templates\TokenType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Closed-set email block document validator.
 * Unknown types/versions are rejected — forward-compat by version bump only.
 * v1 is a flat block list. v2 is sections → columns → blocks.
 */
final class EmailBlockDocument
{
    public const VERSION_1 = 1;

    public const VERSION_2 = 2;

    /** @var array<string, int> */
    public const LAYOUTS = [
        '100' => 1,
        '50_50' => 2,
        '33_67' => 2,
        '67_33' => 2,
        '33_33_33' => 3,
    ];

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
        'info_rows',
        'list',
        'callout',
        'logo_header',
        'footer_social',
    ];

    private const SECTION_ID = '/^sec_[0-9a-f]{8}$/';

    private const COLUMN_ID = '/^col_[0-9a-f]{8}$/';

    private const BLOCK_ID = '/^blk_[0-9a-f]{8}$/';

    private const HEX_COLOR = '/^#[0-9A-Fa-f]{6}$/';

    /** @var list<string> */
    private const PADDING = ['none', 'sm', 'md', 'lg'];

    /**
     * @return array<string, mixed>
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
        if ($version === self::VERSION_1 || $version === (string) self::VERSION_1) {
            return self::validateV1($doc);
        }
        if ($version === self::VERSION_2 || $version === (string) self::VERSION_2) {
            return self::validateV2($doc);
        }

        throw ValidationException::withMessages([
            'blocks.version' => [__('errors.templates.blocks_unknown_version', ['version' => (string) $version])],
        ]);
    }

    /**
     * Email writes store v2 only. v1 is still accepted by {@see validate()} on read.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateForWrite(mixed $doc): array
    {
        if (is_array($doc)) {
            $version = $doc['version'] ?? null;
            if ($version === self::VERSION_1 || $version === (string) self::VERSION_1) {
                throw ValidationException::withMessages([
                    'blocks.version' => [__('errors.templates.blocks_version_write_v1')],
                ]);
            }
        }

        return self::validate($doc);
    }

    /**
     * Pure v1 → v2 conversion. One full-width section, one column, blocks in order.
     * Does not mutate $v1.
     *
     * @param  array<string, mixed>  $v1
     * @return array<string, mixed>
     */
    public static function upcast(array $v1): array
    {
        $source = is_array($v1['blocks'] ?? null) ? array_values($v1['blocks']) : [];
        /** @var array<string, true> $used */
        $used = [];
        $blocks = [];
        $firstOriginalId = '';

        foreach ($source as $block) {
            if (! is_array($block)) {
                continue;
            }
            $oldId = (string) ($block['id'] ?? '');
            if ($firstOriginalId === '' && $oldId !== '') {
                $firstOriginalId = $oldId;
            }
            $blocks[] = [
                'id' => self::derivedId('blk', $oldId, $used, keepWhenValid: true),
                'type' => (string) ($block['type'] ?? ''),
                'params' => is_array($block['params'] ?? null) ? $block['params'] : [],
            ];
        }

        return [
            'version' => self::VERSION_2,
            'settings' => ['background_color' => null],
            'sections' => [[
                'id' => self::derivedId('sec', $firstOriginalId, $used, keepWhenValid: false),
                'layout' => '100',
                'params' => [
                    'background_color' => null,
                    'padding' => 'md',
                    'stack_on_mobile' => true,
                ],
                'columns' => [[
                    'id' => self::derivedId('col', $firstOriginalId, $used, keepWhenValid: false),
                    'blocks' => $blocks,
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{version: int, blocks: list<array{id: string, type: string, params: array<string, mixed>}>}
     */
    private static function validateV1(array $doc): array
    {
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

            $params = self::validateParams($type, $params, "blocks.blocks.{$index}");
            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'params' => $params,
            ];
        }

        return [
            'version' => self::VERSION_1,
            'blocks' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{version: int, settings: array{background_color: ?string}, sections: list<array<string, mixed>>}
     */
    private static function validateV2(array $doc): array
    {
        self::assertLevel(
            $doc,
            ['blocks', 'columns'],
            ['version', 'settings', 'sections'],
            'blocks',
        );

        $settings = self::validateSettings($doc['settings'] ?? null, array_key_exists('settings', $doc));

        $sections = $doc['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections)) {
            self::fail('blocks.sections', 'blocks_invalid');
        }

        /** @var array<string, true> $seen */
        $seen = [];
        $duplicatePath = null;
        $normalized = [];

        foreach ($sections as $sectionIndex => $section) {
            $sectionPath = "blocks.sections.{$sectionIndex}";
            if (! is_array($section)) {
                self::fail($sectionPath, 'blocks_invalid');
            }

            self::assertLevel(
                $section,
                ['sections', 'blocks'],
                ['id', 'layout', 'params', 'columns'],
                $sectionPath,
            );

            $sectionId = self::assertId($section['id'] ?? null, self::SECTION_ID, "{$sectionPath}.id");
            self::trackId($sectionId, "{$sectionPath}.id", $seen, $duplicatePath);

            $layout = $section['layout'] ?? null;
            if (! is_string($layout) || ! array_key_exists($layout, self::LAYOUTS)) {
                self::fail("{$sectionPath}.layout", 'blocks_unknown_layout', [
                    'layout' => is_scalar($layout) ? (string) $layout : '',
                ]);
            }

            $params = self::validateSectionParams($section['params'] ?? null, "{$sectionPath}.params");

            $columns = $section['columns'] ?? null;
            if (! is_array($columns) || ! array_is_list($columns)) {
                self::fail("{$sectionPath}.columns", 'blocks_invalid');
            }
            if (count($columns) !== self::LAYOUTS[$layout]) {
                self::fail("{$sectionPath}.columns", 'blocks_layout_mismatch');
            }

            $normalizedColumns = [];
            foreach ($columns as $columnIndex => $column) {
                $columnPath = "{$sectionPath}.columns.{$columnIndex}";
                if (! is_array($column)) {
                    self::fail($columnPath, 'blocks_invalid');
                }

                self::assertLevel(
                    $column,
                    ['sections', 'columns'],
                    ['id', 'blocks'],
                    $columnPath,
                );

                $columnId = self::assertId($column['id'] ?? null, self::COLUMN_ID, "{$columnPath}.id");
                self::trackId($columnId, "{$columnPath}.id", $seen, $duplicatePath);

                $columnBlocks = $column['blocks'] ?? null;
                if (! is_array($columnBlocks) || ! array_is_list($columnBlocks)) {
                    self::fail("{$columnPath}.blocks", 'blocks_invalid');
                }

                $normalizedBlocks = [];
                foreach ($columnBlocks as $blockIndex => $block) {
                    $blockPath = "{$columnPath}.blocks.{$blockIndex}";
                    if (! is_array($block)) {
                        self::fail($blockPath, 'blocks_invalid');
                    }

                    self::assertLevel(
                        $block,
                        ['sections', 'columns', 'blocks'],
                        ['id', 'type', 'params'],
                        $blockPath,
                    );

                    $blockId = self::assertId($block['id'] ?? null, self::BLOCK_ID, "{$blockPath}.id");
                    self::trackId($blockId, "{$blockPath}.id", $seen, $duplicatePath);

                    $type = $block['type'] ?? null;
                    if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                        self::fail("{$blockPath}.type", 'blocks_unknown_type', [
                            'type' => is_scalar($type) ? (string) $type : '',
                        ]);
                    }
                    if ($type === 'raw_html' && $layout !== '100') {
                        self::fail("{$blockPath}.type", 'blocks_raw_html_in_columns');
                    }

                    $blockParams = $block['params'] ?? null;
                    if (! is_array($blockParams)) {
                        self::fail("{$blockPath}.params", 'blocks_invalid');
                    }

                    $normalizedBlocks[] = [
                        'id' => $blockId,
                        'type' => $type,
                        'params' => self::validateParams($type, $blockParams, $blockPath),
                    ];
                }

                $normalizedColumns[] = [
                    'id' => $columnId,
                    'blocks' => $normalizedBlocks,
                ];
            }

            $normalized[] = [
                'id' => $sectionId,
                'layout' => $layout,
                'params' => $params,
                'columns' => $normalizedColumns,
            ];
        }

        if ($duplicatePath !== null) {
            self::fail($duplicatePath, 'blocks_duplicate_id');
        }

        return [
            'version' => self::VERSION_2,
            'settings' => $settings,
            'sections' => $normalized,
        ];
    }

    /**
     * @return array{background_color: ?string}
     */
    private static function validateSettings(mixed $settings, bool $present): array
    {
        if (! $present || $settings === null) {
            if ($present && $settings === null) {
                self::fail('blocks.settings', 'blocks_invalid');
            }

            return ['background_color' => null];
        }

        if (! is_array($settings)) {
            self::fail('blocks.settings', 'blocks_invalid');
        }

        self::assertLevel($settings, ['sections', 'columns', 'blocks'], ['background_color'], 'blocks.settings');

        return [
            'background_color' => self::assertHexOrNull(
                $settings['background_color'] ?? null,
                'blocks.settings.background_color',
            ),
        ];
    }

    /**
     * @return array{background_color: ?string, padding: string, stack_on_mobile: bool}
     */
    private static function validateSectionParams(mixed $params, string $path): array
    {
        if (! is_array($params)) {
            self::fail($path, 'blocks_invalid');
        }

        self::assertLevel(
            $params,
            ['sections', 'columns', 'blocks'],
            ['background_color', 'padding', 'stack_on_mobile'],
            $path,
        );

        $padding = $params['padding'] ?? null;
        if (! is_string($padding) || ! in_array($padding, self::PADDING, true)) {
            self::fail("{$path}.padding", 'blocks_invalid');
        }

        if (array_key_exists('stack_on_mobile', $params)) {
            if (! is_bool($params['stack_on_mobile'])) {
                self::fail("{$path}.stack_on_mobile", 'blocks_invalid');
            }
            $stack = $params['stack_on_mobile'];
        } else {
            $stack = true;
        }

        return [
            'background_color' => self::assertHexOrNull(
                array_key_exists('background_color', $params) ? $params['background_color'] : null,
                "{$path}.background_color",
            ),
            'padding' => $padding,
            'stack_on_mobile' => $stack,
        ];
    }

    /**
     * @param  list<string>  $nestingForbidden
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $node
     */
    private static function assertLevel(array $node, array $nestingForbidden, array $allowed, string $path): void
    {
        foreach ($nestingForbidden as $key) {
            if (array_key_exists($key, $node)) {
                self::fail("{$path}.{$key}", 'blocks_illegal_nesting');
            }
        }

        foreach (array_keys($node) as $key) {
            if (! in_array($key, $allowed, true)) {
                self::fail("{$path}.{$key}", 'blocks_invalid');
            }
        }
    }

    private static function assertId(mixed $id, string $pattern, string $path): string
    {
        if (! is_string($id) || preg_match($pattern, $id) !== 1) {
            self::fail($path, 'blocks_invalid_id');
        }

        return $id;
    }

    private static function assertHexOrNull(mixed $color, string $path): ?string
    {
        if ($color === null) {
            return null;
        }
        if (! is_string($color) || preg_match(self::HEX_COLOR, $color) !== 1) {
            self::fail($path, 'blocks_invalid');
        }

        return $color;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function trackId(string $id, string $path, array &$seen, ?string &$duplicatePath): void
    {
        if (isset($seen[$id])) {
            if ($duplicatePath === null) {
                $duplicatePath = $path;
            }

            return;
        }

        $seen[$id] = true;
    }

    /**
     * @param  array<string, true>  $used
     */
    private static function derivedId(string $prefix, string $seed, array &$used, bool $keepWhenValid): string
    {
        if ($keepWhenValid && preg_match(self::BLOCK_ID, $seed) === 1 && ! isset($used[$seed])) {
            $used[$seed] = true;

            return $seed;
        }

        $n = 0;
        do {
            $salt = $n === 0 ? "{$prefix}:{$seed}" : "{$prefix}:{$seed}:{$n}";
            $id = $prefix.'_'.substr(sha1($salt), 0, 8);
            $n++;
        } while (isset($used[$id]));

        $used[$id] = true;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private static function fail(string $path, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $path => [__('errors.templates.'.$key, $replace)],
        ]);
    }

    /**
     * Block types and param rules for the assistant. Types follow {@see TYPES}.
     *
     * @return list<array{type: string, params: list<array{name: string, rules: string}>}>
     */
    public static function describeForAssistant(): array
    {
        $rules = self::paramRules();
        $described = [];

        foreach (self::TYPES as $type) {
            $params = [];
            foreach ($rules[$type] ?? [] as $name => $fieldRules) {
                $parts = [];
                foreach ($fieldRules as $rule) {
                    $parts[] = self::compactRule($rule);
                }
                $params[] = [
                    'name' => $name,
                    'rules' => implode(' ', $parts),
                ];
            }
            $described[] = [
                'type' => $type,
                'params' => $params,
            ];
        }

        return $described;
    }

    /**
     * @return array<string, array<string, list<mixed>>>
     */
    private static function paramRules(): array
    {
        return [
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
            'info_rows' => [
                'rows' => ['required', 'array', 'min:1', 'max:12'],
                'rows.*.label' => ['required', 'string', 'max:120'],
                'rows.*.value' => ['required', 'string', 'max:255'],
                'striped' => ['required', 'boolean'],
                'value_align' => ['required', 'string', 'in:left,right'],
            ],
            'list' => [
                'style' => ['required', 'string', 'in:bullet,numbered'],
                'items' => ['required', 'array', 'min:1', 'max:20'],
                'items.*' => ['required', 'string'],
            ],
            'callout' => [
                'tone' => ['required', 'string', 'in:info,warning,danger'],
                'title' => ['nullable', 'string', 'max:120'],
                'html' => ['required', 'string'],
            ],
            'logo_header' => [
                'align' => ['required', 'string', 'in:left,center'],
                'max_width' => ['required', 'integer', 'min:60', 'max:300'],
                'link_url' => ['nullable', 'string', 'max:2000'],
            ],
            'footer_social' => [
                'links' => ['present', 'array', 'max:6'],
                'links.*.network' => ['required', 'string', Rule::in(SocialIcon::NETWORKS)],
                'links.*.url' => ['required', 'string', 'max:2000'],
                'address' => ['nullable', 'string', 'max:500'],
            ],
        ];
    }

    private static function compactRule(mixed $rule): string
    {
        if (is_string($rule)) {
            return $rule;
        }

        if ($rule instanceof \Stringable) {
            return (string) $rule;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private static function validateParams(string $type, array $params, string $path): array
    {
        $rules = self::paramRules()[$type] ?? [];

        if ($rules === []) {
            return [];
        }

        $validator = Validator::make($params, $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                $messages["{$path}.params.{$field}"] = $fieldMessages;
            }
            throw ValidationException::withMessages($messages);
        }

        $validated = $validator->validated();

        if ($type === 'image') {
            $assetId = $validated['asset_id'] ?? null;
            $url = $validated['url'] ?? null;
            if (($assetId === null || $assetId === '') && ($url === null || $url === '')) {
                throw ValidationException::withMessages([
                    "{$path}.params" => [__('errors.templates.image_ref_required')],
                ]);
            }
        }

        if ($type === 'button') {
            self::assertAllowedLink((string) $validated['url'], "{$path}.params.url");
        }
        if ($type === 'logo_header') {
            $link = $validated['link_url'] ?? null;
            if (is_string($link) && $link !== '') {
                self::assertAllowedLink($link, "{$path}.params.link_url");
            }
        }
        if ($type === 'footer_social') {
            $links = $validated['links'] ?? [];
            if (is_array($links)) {
                foreach ($links as $index => $link) {
                    if (! is_array($link)) {
                        continue;
                    }
                    self::assertAllowedLink((string) ($link['url'] ?? ''), "{$path}.params.links.{$index}.url");
                }
            }
        }

        return $validated;
    }

    private static function assertAllowedLink(string $value, string $path): void
    {
        if (preg_match('/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/', $value, $matches) === 1) {
            $token = TokenCatalog::find($matches[1]);
            if ($token !== null && $token->type === TokenType::Url) {
                return;
            }
            self::fail($path, 'link_invalid_scheme');
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (is_string($scheme) && in_array(strtolower($scheme), ['https', 'mailto', 'tel'], true)) {
            return;
        }

        self::fail($path, 'link_invalid_scheme');
    }
}
