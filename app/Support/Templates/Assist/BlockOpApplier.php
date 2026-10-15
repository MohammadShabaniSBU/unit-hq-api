<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

/**
 * PHP port of the panel applyOps. One op in, the next document and its inverse out.
 * Ids the op does not carry are sec_/col_/blk_ plus 8 hex.
 */
final class BlockOpApplier
{
    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $op
     * @return array{doc: array<string, mixed>, inverse: list<array<string, mixed>>, op: array<string, mixed>}
     */
    public static function apply(array $doc, array $op): array
    {
        /** @var array<string, mixed> $next */
        $next = self::cloneValue($doc);
        if (! isset($next['sections']) || ! is_array($next['sections']) || ! array_is_list($next['sections'])) {
            $next['sections'] = [];
        }

        /** @var array<string, mixed> $concrete */
        $concrete = self::cloneValue($op);
        $inverse = self::applyOne($next, $concrete);

        return [
            'doc' => $next,
            'inverse' => $inverse,
            'op' => $concrete,
        ];
    }

    /**
     * @param  array<string, true>  $used
     */
    public static function makeId(string $prefix, array &$used): string
    {
        do {
            $id = $prefix.'_'.bin2hex(random_bytes(4));
        } while (isset($used[$id]));

        $used[$id] = true;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private static function applyOne(array &$doc, array &$op): array
    {
        $name = $op['op'] ?? null;

        return match ($name) {
            'add_section' => self::addSection($doc, $op),
            'remove_section' => self::removeSection($doc, self::string($op['section_id'] ?? null)),
            'move_section' => self::moveSection($doc, self::string($op['section_id'] ?? null), (int) ($op['to_index'] ?? 0)),
            'set_section_layout' => self::setSectionLayout($doc, self::string($op['section_id'] ?? null), self::string($op['layout'] ?? null)),
            'update_section' => self::updateSection($doc, self::string($op['section_id'] ?? null), self::record($op['params'] ?? null)),
            'insert_blocks' => self::insertBlocks($doc, $op),
            'update_block' => self::updateBlock($doc, self::string($op['block_id'] ?? null), self::record($op['params'] ?? null)),
            'move_block' => self::moveBlock(
                $doc,
                self::string($op['block_id'] ?? null),
                self::string($op['to_column_id'] ?? null),
                (int) ($op['to_index'] ?? 0),
            ),
            'remove_block' => self::removeBlock($doc, self::string($op['block_id'] ?? null)),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private static function addSection(array &$doc, array &$op): array
    {
        $section = $op['section'] ?? null;
        if (! is_array($section)) {
            return [];
        }

        $supplied = $section['id'] ?? null;
        if (is_string($supplied) && $supplied !== '' && self::findSectionIndex($doc, $supplied) >= 0) {
            return [];
        }

        /** @var array<string, true> $used */
        $used = self::collectIds($doc);
        self::ensureSectionIds($section, $used);
        $op['section'] = $section;

        $index = (int) ($op['index'] ?? 0);
        $at = self::clamp($index, 0, count($doc['sections']));
        /** @var array<string, mixed> $copy */
        $copy = self::cloneValue($section);
        array_splice($doc['sections'], $at, 0, [$copy]);

        return [['op' => 'remove_section', 'section_id' => $section['id']]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function removeSection(array &$doc, string $sectionId): array
    {
        $index = self::findSectionIndex($doc, $sectionId);
        if ($index < 0) {
            return [];
        }

        $section = $doc['sections'][$index];
        array_splice($doc['sections'], $index, 1);

        return [['op' => 'add_section', 'index' => $index, 'section' => $section]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function moveSection(array &$doc, string $sectionId, int $toIndex): array
    {
        $from = self::findSectionIndex($doc, $sectionId);
        if ($from < 0) {
            return [];
        }

        $section = $doc['sections'][$from];
        array_splice($doc['sections'], $from, 1);
        $dest = self::clamp($toIndex, 0, count($doc['sections']));
        array_splice($doc['sections'], $dest, 0, [$section]);
        if ($dest === $from) {
            return [];
        }

        return [['op' => 'move_section', 'section_id' => $sectionId, 'to_index' => $from]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function setSectionLayout(array &$doc, string $sectionId, string $layout): array
    {
        $index = self::findSectionIndex($doc, $sectionId);
        if ($index < 0 || $layout === '') {
            return [];
        }

        /** @var array<string, mixed> $section */
        $section = $doc['sections'][$index];
        $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
        $nextCount = self::columnCount($layout);
        if (($section['layout'] ?? null) === $layout && count($columns) === $nextCount) {
            return [];
        }
        if ($layout !== '100' && self::sectionHasRawHtml($section)) {
            return [];
        }

        $shrinking = $nextCount < count($columns);
        /** @var array<string, mixed>|null $snapshot */
        $snapshot = $shrinking ? self::cloneValue($section) : null;
        $previousLayout = is_string($section['layout'] ?? null) ? $section['layout'] : '100';

        if ($shrinking) {
            $kept = array_slice($columns, 0, $nextCount);
            $removed = array_slice($columns, $nextCount);
            $last = count($kept) - 1;
            foreach ($removed as $column) {
                if (! is_array($column)) {
                    continue;
                }
                $blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
                foreach ($blocks as $block) {
                    $kept[$last]['blocks'][] = $block;
                }
            }
            $section['columns'] = array_values($kept);
        } elseif ($nextCount > count($columns)) {
            /** @var array<string, true> $used */
            $used = self::collectIds($doc);
            while (count($columns) < $nextCount) {
                $columns[] = [
                    'id' => self::makeId('col', $used),
                    'blocks' => [],
                ];
            }
            $section['columns'] = $columns;
        }

        $section['layout'] = $layout;
        $doc['sections'][$index] = $section;

        if (is_array($snapshot)) {
            return [
                ['op' => 'remove_section', 'section_id' => $sectionId],
                ['op' => 'add_section', 'index' => $index, 'section' => $snapshot],
            ];
        }

        return [['op' => 'set_section_layout', 'section_id' => $sectionId, 'layout' => $previousLayout]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private static function updateSection(array &$doc, string $sectionId, array $params): array
    {
        $index = self::findSectionIndex($doc, $sectionId);
        if ($index < 0) {
            return [];
        }

        /** @var array<string, mixed> $section */
        $section = $doc['sections'][$index];
        $current = is_array($section['params'] ?? null) ? $section['params'] : [];
        $previous = self::mergeRecord($current, $params);
        if ($previous === null) {
            return [];
        }

        $section['params'] = $current;
        $doc['sections'][$index] = $section;

        return [['op' => 'update_section', 'section_id' => $sectionId, 'params' => $previous]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private static function insertBlocks(array &$doc, array &$op): array
    {
        $blocks = $op['blocks'] ?? null;
        if (! is_array($blocks) || $blocks === [] || ! array_is_list($blocks)) {
            return [];
        }

        $columnId = self::string($op['column_id'] ?? null);
        $location = self::findColumn($doc, $columnId);
        if ($location === null) {
            return [];
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'raw_html' && $location['layout'] !== '100') {
                return [];
            }
        }

        /** @var array<string, true> $used */
        $used = self::collectIds($doc);
        $copies = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                return [];
            }
            /** @var array<string, mixed> $copy */
            $copy = self::cloneValue($block);
            $id = $copy['id'] ?? null;
            if (! is_string($id) || $id === '') {
                $copy['id'] = self::makeId('blk', $used);
            } else {
                $used[$id] = true;
            }
            $copies[] = $copy;
        }

        $op['blocks'] = $copies;
        $at = self::clamp((int) ($op['index'] ?? 0), 0, count($location['blocks']));
        array_splice(
            $doc['sections'][$location['section_index']]['columns'][$location['column_index']]['blocks'],
            $at,
            0,
            $copies,
        );

        $inverse = [];
        foreach ($copies as $copy) {
            $inverse[] = ['op' => 'remove_block', 'block_id' => $copy['id']];
        }

        return array_reverse($inverse);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private static function updateBlock(array &$doc, string $blockId, array $params): array
    {
        $location = self::findBlock($doc, $blockId);
        if ($location === null) {
            return [];
        }

        $current = is_array($location['params']) ? $location['params'] : [];
        $previous = self::mergeRecord($current, $params);
        if ($previous === null) {
            return [];
        }

        $doc['sections'][$location['section_index']]['columns'][$location['column_index']]['blocks'][$location['block_index']]['params'] = $current;

        return [['op' => 'update_block', 'block_id' => $blockId, 'params' => $previous]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function moveBlock(array &$doc, string $blockId, string $toColumnId, int $toIndex): array
    {
        $source = self::findBlock($doc, $blockId);
        $target = self::findColumn($doc, $toColumnId);
        if ($source === null || $target === null) {
            return [];
        }

        if ($target['layout'] !== '100' && $source['type'] === 'raw_html' && $target['column_id'] !== $source['column_id']) {
            return [];
        }
        if ($source['column_id'] === $target['column_id'] && $source['block_index'] === $toIndex) {
            return [];
        }

        $fromColumnId = $source['column_id'];
        $fromIndex = $source['block_index'];
        $block = $doc['sections'][$source['section_index']]['columns'][$source['column_index']]['blocks'][$fromIndex];
        array_splice(
            $doc['sections'][$source['section_index']]['columns'][$source['column_index']]['blocks'],
            $fromIndex,
            1,
        );

        $destLocation = self::findColumn($doc, $toColumnId);
        if ($destLocation === null) {
            return [];
        }

        $dest = self::clamp($toIndex, 0, count($destLocation['blocks']));
        array_splice(
            $doc['sections'][$destLocation['section_index']]['columns'][$destLocation['column_index']]['blocks'],
            $dest,
            0,
            [$block],
        );

        if ($fromColumnId === $destLocation['column_id'] && $dest === $fromIndex) {
            return [];
        }

        return [[
            'op' => 'move_block',
            'block_id' => $blockId,
            'to_column_id' => $fromColumnId,
            'to_index' => $fromIndex,
        ]];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    private static function removeBlock(array &$doc, string $blockId): array
    {
        $location = self::findBlock($doc, $blockId);
        if ($location === null) {
            return [];
        }

        $block = $doc['sections'][$location['section_index']]['columns'][$location['column_index']]['blocks'][$location['block_index']];
        array_splice(
            $doc['sections'][$location['section_index']]['columns'][$location['column_index']]['blocks'],
            $location['block_index'],
            1,
        );

        return [[
            'op' => 'insert_blocks',
            'column_id' => $location['column_id'],
            'index' => $location['block_index'],
            'blocks' => [$block],
        ]];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, true>  $used
     */
    private static function ensureSectionIds(array &$section, array &$used): void
    {
        $id = $section['id'] ?? null;
        if (! is_string($id) || $id === '') {
            $section['id'] = self::makeId('sec', $used);
        } else {
            $used[$id] = true;
        }

        $columns = is_array($section['columns'] ?? null) && array_is_list($section['columns'])
            ? $section['columns']
            : [];

        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                $column = ['blocks' => []];
            }
            $columnId = $column['id'] ?? null;
            if (! is_string($columnId) || $columnId === '') {
                $column['id'] = self::makeId('col', $used);
            } else {
                $used[$columnId] = true;
            }
            $blocks = is_array($column['blocks'] ?? null) && array_is_list($column['blocks'])
                ? $column['blocks']
                : [];
            foreach ($blocks as $blockIndex => $block) {
                if (! is_array($block)) {
                    continue;
                }
                $blockId = $block['id'] ?? null;
                if (! is_string($blockId) || $blockId === '') {
                    $block['id'] = self::makeId('blk', $used);
                } else {
                    $used[$blockId] = true;
                }
                $blocks[$blockIndex] = $block;
            }
            $column['blocks'] = $blocks;
            $columns[$index] = $column;
        }

        $section['columns'] = $columns;
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function findSectionIndex(array $doc, string $sectionId): int
    {
        if ($sectionId === '') {
            return -1;
        }

        foreach ($doc['sections'] as $index => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                return (int) $index;
            }
        }

        return -1;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{section_index: int, column_index: int, column_id: string, layout: string, blocks: list<mixed>}|null
     */
    private static function findColumn(array $doc, string $columnId): ?array
    {
        if ($columnId === '') {
            return null;
        }

        foreach ($doc['sections'] as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
            foreach ($columns as $columnIndex => $column) {
                if (! is_array($column) || ($column['id'] ?? null) !== $columnId) {
                    continue;
                }
                $blocks = is_array($column['blocks'] ?? null) && array_is_list($column['blocks'])
                    ? $column['blocks']
                    : [];

                return [
                    'section_index' => (int) $sectionIndex,
                    'column_index' => (int) $columnIndex,
                    'column_id' => $columnId,
                    'layout' => is_string($section['layout'] ?? null) ? $section['layout'] : '',
                    'blocks' => $blocks,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{section_index: int, column_index: int, block_index: int, column_id: string, type: string, params: array<string, mixed>}|null
     */
    private static function findBlock(array $doc, string $blockId): ?array
    {
        if ($blockId === '') {
            return null;
        }

        foreach ($doc['sections'] as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
            foreach ($columns as $columnIndex => $column) {
                if (! is_array($column)) {
                    continue;
                }
                $blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
                foreach ($blocks as $blockIndex => $block) {
                    if (! is_array($block) || ($block['id'] ?? null) !== $blockId) {
                        continue;
                    }

                    return [
                        'section_index' => (int) $sectionIndex,
                        'column_index' => (int) $columnIndex,
                        'block_index' => (int) $blockIndex,
                        'column_id' => is_string($column['id'] ?? null) ? $column['id'] : '',
                        'type' => is_string($block['type'] ?? null) ? $block['type'] : '',
                        'params' => is_array($block['params'] ?? null) ? $block['params'] : [],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private static function sectionHasRawHtml(array $section): bool
    {
        $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }
            $blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'raw_html') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function columnCount(string $layout): int
    {
        if ($layout === '100') {
            return 1;
        }
        if ($layout === '33_33_33') {
            return 3;
        }

        return 2;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>|null
     */
    private static function mergeRecord(array &$target, array $patch): ?array
    {
        $previous = [];
        $changed = false;
        foreach ($patch as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $next = self::cloneValue($value);
            $has = array_key_exists($key, $target);
            if ($has && self::sameValue($target[$key], $next)) {
                continue;
            }
            $changed = true;
            $previous[$key] = $has ? self::cloneValue($target[$key]) : null;
            $target[$key] = $next;
        }

        return $changed ? $previous : null;
    }

    private static function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }

    private static function cloneValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, true>
     */
    private static function collectIds(array $doc): array
    {
        /** @var array<string, true> $ids */
        $ids = [];
        foreach ($doc['sections'] as $section) {
            if (! is_array($section)) {
                continue;
            }
            if (is_string($section['id'] ?? null) && $section['id'] !== '') {
                $ids[$section['id']] = true;
            }
            $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
            foreach ($columns as $column) {
                if (! is_array($column)) {
                    continue;
                }
                if (is_string($column['id'] ?? null) && $column['id'] !== '') {
                    $ids[$column['id']] = true;
                }
                $blocks = is_array($column['blocks'] ?? null) ? $column['blocks'] : [];
                foreach ($blocks as $block) {
                    if (is_array($block) && is_string($block['id'] ?? null) && $block['id'] !== '') {
                        $ids[$block['id']] = true;
                    }
                }
            }
        }

        return $ids;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($value, $max));
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $record[$key] = $item;
            }
        }

        return $record;
    }
}
