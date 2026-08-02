<?php

declare(strict_types=1);

namespace App\Support\Reports;

use InvalidArgumentException;

/**
 * One shape for JSON tables, CSV export, and dashboard tiles.
 * Money is never cross-currency: a money column has exactly one currency;
 * mixed currency input throws.
 *
 * Optional {@see $meta} carries footers, honesty notes, headlines, and
 * trend series. CSV export uses columns + rows only.
 */
final readonly class ReportResult
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public array $meta = [],
    ) {
        foreach ($this->columns as $column) {
            if (! $column instanceof ReportColumn) {
                throw new InvalidArgumentException('ReportResult columns must be ReportColumn instances.');
            }
        }
    }

    /**
     * Build a money column from values already grouped by currency.
     * Exactly one currency key is allowed — mixed input throws.
     *
     * @param  array<string, mixed>  $byCurrency  currency => payload (unused beyond keys)
     */
    public static function moneyColumn(string $key, string $label, array $byCurrency): ReportColumn
    {
        $currencies = array_values(array_unique(array_map(
            static fn (string|int $c): string => strtoupper(trim((string) $c)),
            array_keys($byCurrency),
        )));

        $currencies = array_values(array_filter($currencies, static fn (string $c): bool => $c !== ''));

        if ($currencies === []) {
            throw new InvalidArgumentException("Money column [{$key}] requires a currency.");
        }

        if (count($currencies) > 1) {
            throw new InvalidArgumentException("Money column [{$key}] cannot mix currencies.");
        }

        return ReportColumn::money($key, $label, $currencies[0]);
    }

    /**
     * @return array{
     *     columns: list<array{key: string, label: string, type: string, currency: string|null}>,
     *     rows: list<array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'columns' => array_map(
                static fn (ReportColumn $column): array => $column->toArray(),
                $this->columns,
            ),
            'rows' => $this->rows,
            'meta' => $this->meta,
        ];
    }
}
