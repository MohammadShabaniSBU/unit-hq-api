<?php

declare(strict_types=1);

namespace App\Support\Facility;

use App\Models\Employee;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Support\Auth\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UnitCsv
{
    /** @var list<string> */
    public const COLUMNS = [
        'site_code',
        'unit_class_code',
        'unit_number',
        'actual_width',
        'actual_depth',
        'actual_height',
        'note',
        'enabled',
    ];

    /**
     * @param  Collection<int, Unit>  $units
     */
    public static function export(Collection $units): string
    {
        $lines = [self::row(self::COLUMNS, ',')];

        $sorted = $units->sortBy([
            static fn (Unit $unit): string => (string) ($unit->site?->code ?? ''),
            static fn (Unit $unit): string => $unit->unit_number,
        ])->values();

        foreach ($sorted as $unit) {
            $lines[] = self::row([
                (string) ($unit->site?->code ?? ''),
                (string) ($unit->unitClass?->code ?? ''),
                $unit->unit_number,
                self::formatDecimal($unit->actual_width),
                self::formatDecimal($unit->actual_depth),
                self::formatDecimal($unit->actual_height),
                (string) ($unit->note ?? ''),
                $unit->enabled ? 'true' : 'false',
            ], ',');
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    public static function import(string $contents, Employee $employee): UnitCsvImportResult
    {
        $parsed = self::parse($contents);
        if ($parsed['errors'] !== []) {
            return new UnitCsvImportResult(errors: $parsed['errors']);
        }

        /** @var Collection<string, Site> $sites */
        $sites = Site::query()->active()
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->get()
            ->keyBy(static fn (Site $site): string => (string) $site->code);

        /** @var Collection<string, UnitClass> $classes */
        $classes = UnitClass::query()
            ->get()
            ->keyBy(static fn (UnitClass $unitClass): string => $unitClass->code);

        $validated = [];
        $seen = [];
        $errors = [];

        foreach ($parsed['rows'] as $row) {
            $line = $row['line'];
            $cells = $row['cells'];
            $rowErrors = self::validateRow($cells, $sites, $classes, $employee, $seen);

            if ($rowErrors !== []) {
                foreach ($rowErrors as $message) {
                    $errors[] = ['row' => $line, 'message' => $message];
                }

                continue;
            }

            $site = $sites[$cells['site_code']];
            $key = $site->id.'|'.$cells['unit_number'];
            $seen[$key] = true;
            $validated[] = [
                'site' => $site,
                'unit_class' => $classes[$cells['unit_class_code']],
                'unit_number' => $cells['unit_number'],
                'actual_width' => $cells['actual_width'],
                'actual_depth' => $cells['actual_depth'],
                'actual_height' => $cells['actual_height'],
                'note' => $cells['note'],
                'enabled' => $cells['enabled'],
            ];
        }

        if ($errors !== []) {
            return new UnitCsvImportResult(errors: $errors);
        }

        $siteIds = collect($validated)->map(static fn (array $row): int => $row['site']->id)->unique()->all();
        $existing = Unit::query()
            ->whereIn('site_id', $siteIds)
            ->get()
            ->keyBy(static fn (Unit $unit): string => $unit->site_id.'|'.$unit->unit_number);

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($validated, $existing, &$created, &$updated): void {
            foreach ($validated as $row) {
                $key = $row['site']->id.'|'.$row['unit_number'];
                $payload = [
                    'site_id' => $row['site']->id,
                    'unit_class_id' => $row['unit_class']->id,
                    'unit_number' => $row['unit_number'],
                    'actual_width' => $row['actual_width'],
                    'actual_depth' => $row['actual_depth'],
                    'actual_height' => $row['actual_height'],
                    'note' => $row['note'],
                ];

                $unit = $existing->get($key);
                if ($unit === null) {
                    $payload['enabled'] = $row['enabled'] ?? true;
                    Unit::query()->create($payload);
                    $created++;

                    continue;
                }

                if ($row['enabled'] !== null) {
                    $payload['enabled'] = $row['enabled'];
                }

                $unit->update($payload);
                $updated++;
            }
        });

        return new UnitCsvImportResult(created: $created, updated: $updated);
    }

    /**
     * @return array{rows: list<array{line: int, cells: array<string, mixed>}>, errors: list<array{row: int, message: string}>}
     */
    private static function parse(string $contents): array
    {
        $contents = str_starts_with($contents, "\xEF\xBB\xBF")
            ? substr($contents, 3)
            : $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $rawLines = explode("\n", $contents);

        $lines = [];
        foreach ($rawLines as $index => $raw) {
            if ($index === 0 && $raw === '') {
                continue;
            }

            if ($raw === '' && $index === array_key_last($rawLines)) {
                continue;
            }

            $lines[] = $raw;
        }

        if ($lines === []) {
            return [
                'rows' => [],
                'errors' => [['row' => 1, 'message' => __('errors.units.csv_missing_header')]],
            ];
        }

        $separator = self::detectSeparator($lines[0]);
        $headerCells = array_map(
            static fn (string $cell): string => strtolower(trim($cell)),
            self::parseLine($lines[0], $separator),
        );

        if ($headerCells !== self::COLUMNS) {
            return [
                'rows' => [],
                'errors' => [['row' => 1, 'message' => __('errors.units.csv_invalid_header')]],
            ];
        }

        $rows = [];
        $errors = [];

        foreach (array_slice($lines, 1) as $offset => $line) {
            $excelRow = $offset + 2;
            if (trim($line) === '') {
                continue;
            }

            $values = self::parseLine($line, $separator);
            if (count($values) !== count(self::COLUMNS)) {
                $errors[] = ['row' => $excelRow, 'message' => __('errors.units.csv_column_count')];

                continue;
            }

            $cells = [];
            foreach (self::COLUMNS as $index => $column) {
                $cells[$column] = trim($values[$index]);
            }

            $rows[] = [
                'line' => $excelRow,
                'cells' => self::normalizeCells($cells, $separator),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, mixed>
     */
    private static function normalizeCells(array $cells, string $separator): array
    {
        return [
            'site_code' => $cells['site_code'],
            'unit_class_code' => $cells['unit_class_code'],
            'unit_number' => $cells['unit_number'],
            'actual_width' => self::parseDecimal($cells['actual_width'], $separator),
            'actual_depth' => self::parseDecimal($cells['actual_depth'], $separator),
            'actual_height' => self::parseDecimal($cells['actual_height'], $separator),
            'note' => $cells['note'] === '' ? null : $cells['note'],
            'enabled_raw' => $cells['enabled'],
            'enabled' => self::parseEnabled($cells['enabled']),
        ];
    }

    /**
     * @param  array<string, mixed>  $cells
     * @param  Collection<string, Site>  $sites
     * @param  Collection<string, UnitClass>  $classes
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private static function validateRow(
        array $cells,
        Collection $sites,
        Collection $classes,
        Employee $employee,
        array $seen,
    ): array {
        $errors = [];

        if ($cells['site_code'] === '') {
            $errors[] = __('errors.units.csv_site_code_required');
        } elseif (! $sites->has($cells['site_code'])) {
            $errors[] = __('errors.units.csv_unknown_site', ['code' => $cells['site_code']]);
        } elseif (! $employee->allowsPermission(Permission::UnitManage, $sites[$cells['site_code']])) {
            $errors[] = __('errors.units.csv_site_forbidden', ['code' => $cells['site_code']]);
        }

        if ($cells['unit_class_code'] === '') {
            $errors[] = __('errors.units.csv_unit_class_code_required');
        } elseif (! $classes->has($cells['unit_class_code'])) {
            $errors[] = __('errors.units.csv_unknown_unit_class', ['code' => $cells['unit_class_code']]);
        }

        if ($cells['unit_number'] === '') {
            $errors[] = __('errors.units.csv_unit_number_required');
        } elseif (strlen($cells['unit_number']) > 255) {
            $errors[] = __('errors.units.csv_unit_number_too_long');
        }

        foreach (['actual_width', 'actual_depth', 'actual_height'] as $field) {
            if ($cells[$field] === false) {
                $errors[] = __('errors.units.csv_invalid_decimal', ['field' => $field]);
            }
        }

        if ($cells['enabled_raw'] !== '' && $cells['enabled'] === null) {
            $errors[] = __('errors.units.csv_invalid_enabled');
        }

        if ($errors === [] && $cells['site_code'] !== '' && $cells['unit_number'] !== '') {
            $site = $sites[$cells['site_code']];
            $key = $site->id.'|'.$cells['unit_number'];
            if (isset($seen[$key])) {
                $errors[] = __('errors.units.csv_duplicate_unit');
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function parseLine(string $line, string $separator): array
    {
        $cells = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($inQuotes) {
                if ($char === '"') {
                    if ($i + 1 < $length && $line[$i + 1] === '"') {
                        $current .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }

                    continue;
                }

                $current .= $char;

                continue;
            }

            if ($char === '"') {
                $inQuotes = true;

                continue;
            }

            if ($char === $separator) {
                $cells[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $cells[] = $current;

        return $cells;
    }

    /**
     * @param  list<string>  $cells
     */
    private static function row(array $cells, string $separator): string
    {
        return implode($separator, array_map(
            static fn (string $cell): string => self::escape($cell, $separator),
            $cells,
        ));
    }

    private static function escape(string $cell, string $separator): string
    {
        $needsQuotes = str_contains($cell, $separator)
            || str_contains($cell, '"')
            || str_contains($cell, "\n")
            || str_contains($cell, "\r");

        if (! $needsQuotes) {
            return $cell;
        }

        return '"'.str_replace('"', '""', $cell).'"';
    }

    private static function detectSeparator(string $header): string
    {
        $commaCount = substr_count($header, ',');
        $semicolonCount = substr_count($header, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    private static function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }

    private static function parseDecimal(string $value, string $separator): string|false|null
    {
        if ($value === '') {
            return null;
        }

        $normalized = $separator === ';'
            ? str_replace(',', '.', $value)
            : $value;

        if (! is_numeric($normalized) || (float) $normalized < 0) {
            return false;
        }

        return $normalized;
    }

    private static function parseEnabled(string $value): ?bool
    {
        if ($value === '') {
            return null;
        }

        return match (strtolower($value)) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }
}
