<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

use InvalidArgumentException;

/**
 * Declarative description of one chart. The panel renders any spec;
 * reports do not ship per-chart view code.
 */
final readonly class ChartSpec
{
    /**
     * @param  list<string>  $categories
     * @param  list<ChartSeries>  $series
     * @param  list<array{y: int|float, label_key: string}>  $targets
     */
    public function __construct(
        public string $key,
        public ChartType $type,
        public string $titleKey,
        public ?string $descriptionKey,
        public array $categories,
        public CategoryKind $categoryKind,
        public array $series,
        public ChartFormat $format,
        public ?ChartFormat $secondaryFormat = null,
        public ?string $currency = null,
        public array $targets = [],
        public bool $percentStacked = false,
        public ChartWidth $width = ChartWidth::Full,
    ) {
        if ($this->series === []) {
            throw new InvalidArgumentException("Chart [{$this->key}] requires at least one series.");
        }

        foreach ($this->series as $series) {
            if (! $series instanceof ChartSeries) {
                throw new InvalidArgumentException("Chart [{$this->key}] series must be ChartSeries instances.");
            }
        }

        $currency = $this->currency !== null ? strtoupper(trim($this->currency)) : '';
        if ($this->format === ChartFormat::Money && $currency === '') {
            throw new InvalidArgumentException("Chart [{$this->key}] money format requires a currency.");
        }
        if ($this->secondaryFormat === ChartFormat::Money && $currency === '') {
            throw new InvalidArgumentException("Chart [{$this->key}] money format requires a currency.");
        }

        if (
            ($this->type === ChartType::Donut || $this->type === ChartType::Funnel)
            && count($this->series) !== 1
        ) {
            throw new InvalidArgumentException("Chart [{$this->key}] of type {$this->type->value} requires exactly one series.");
        }

        $categoryCount = count($this->categories);
        foreach ($this->series as $series) {
            if (count($series->data) !== $categoryCount) {
                throw new InvalidArgumentException("Chart [{$this->key}] series length must equal the category count.");
            }
        }

        if ($this->type !== ChartType::Combo) {
            if ($this->secondaryFormat !== null) {
                throw new InvalidArgumentException("Chart [{$this->key}] secondary format is combo-only.");
            }
            foreach ($this->series as $series) {
                if ($series->axis === 1) {
                    throw new InvalidArgumentException("Chart [{$this->key}] secondary axis is combo-only.");
                }
            }
        }

        foreach ($this->targets as $target) {
            if (! isset($target['y'], $target['label_key']) || ! is_numeric($target['y']) || ! is_string($target['label_key']) || $target['label_key'] === '') {
                throw new InvalidArgumentException("Chart [{$this->key}] targets require y and label_key.");
            }
        }
    }

    /**
     * @return array{
     *     key: string,
     *     type: string,
     *     title_key: string,
     *     description_key: string|null,
     *     categories: list<string>,
     *     category_kind: string,
     *     series: list<array{name_key: string|null, name: string|null, data: list<int|float|null>, kind: string|null, axis: int}>,
     *     format: string,
     *     secondary_format: string|null,
     *     currency: string|null,
     *     targets: list<array{y: int|float, label_key: string}>,
     *     percent_stacked: bool,
     *     width: string,
     *     empty: bool
     * }
     */
    public function toArray(): array
    {
        $currency = $this->currency !== null && trim($this->currency) !== ''
            ? strtoupper(trim($this->currency))
            : null;

        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'title_key' => $this->titleKey,
            'description_key' => $this->descriptionKey,
            'categories' => $this->categories,
            'category_kind' => $this->categoryKind->value,
            'series' => array_map(
                static fn (ChartSeries $series): array => $series->toArray(),
                $this->series,
            ),
            'format' => $this->format->value,
            'secondary_format' => $this->secondaryFormat?->value,
            'currency' => $currency,
            'targets' => array_values($this->targets),
            'percent_stacked' => $this->percentStacked,
            'width' => $this->width->value,
            'empty' => $this->isEmpty(),
        ];
    }

    private function isEmpty(): bool
    {
        foreach ($this->series as $series) {
            foreach ($series->data as $value) {
                if ($value !== null && $value != 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
