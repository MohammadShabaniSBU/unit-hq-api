<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * Locale-aware CSV for Excel. es → `;` separators and `,` decimals;
 * otherwise `,` + `.`. Always UTF-8 with BOM.
 */
final class CsvExporter
{
    public static function export(ReportResult $result, string $locale = 'en'): string
    {
        $isEs = strtolower($locale) === 'es';
        $separator = $isEs ? ';' : ',';

        $lines = [];
        $lines[] = self::row(
            array_map(static fn (ReportColumn $c): string => $c->label, $result->columns),
            $separator,
        );

        foreach ($result->rows as $row) {
            $cells = [];
            foreach ($result->columns as $column) {
                $cells[] = self::formatCell(
                    $row[$column->key] ?? null,
                    $column,
                    $isEs,
                );
            }
            $lines[] = self::row($cells, $separator);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    public static function filename(
        string $reportName,
        ReportFilters $filters,
    ): string {
        $sitePart = 'all';
        if ($filters->siteIds !== null && count($filters->siteIds) === 1) {
            $sitePart = (string) $filters->siteIds[0];
        } elseif ($filters->siteIds !== null && $filters->siteIds !== []) {
            $sitePart = 'sites';
        }

        $range = 'all';
        if ($filters->asOf !== null) {
            $range = $filters->asOf;
        } elseif ($filters->from !== null || $filters->to !== null) {
            $range = ($filters->from ?? 'start').'_'.($filters->to ?? 'end');
        }

        return sprintf('%s-%s-%s.csv', $reportName, $sitePart, $range);
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

    private static function formatCell(mixed $value, ReportColumn $column, bool $isEs): string
    {
        if ($value === null) {
            return '';
        }

        if ($column->type === ReportColumnType::Money || $column->type === ReportColumnType::Percent) {
            $numeric = is_string($value) || is_int($value) || is_float($value)
                ? (string) $value
                : '';

            if ($numeric === '') {
                return '';
            }

            // Bare number for Excel; locale only changes decimal mark.
            if ($isEs) {
                return str_replace('.', ',', $numeric);
            }

            return $numeric;
        }

        if ($column->type === ReportColumnType::Int) {
            return (string) (int) $value;
        }

        return (string) $value;
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
}
