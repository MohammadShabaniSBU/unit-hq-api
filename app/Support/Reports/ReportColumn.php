<?php

declare(strict_types=1);

namespace App\Support\Reports;

use InvalidArgumentException;

/**
 * Column meta shared by the table renderer, CSV exporter, and dashboard.
 * Money columns always carry a single ISO currency (invariant 31).
 */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ReportColumnType $type,
        public ?string $currency = null,
    ) {
        if ($this->type === ReportColumnType::Money) {
            $normalized = strtoupper(trim((string) $this->currency));
            if ($normalized === '') {
                throw new InvalidArgumentException("Money column [{$this->key}] requires a currency.");
            }
        }
    }

    public static function money(string $key, string $label, string $currency): self
    {
        return new self($key, $label, ReportColumnType::Money, strtoupper(trim($currency)));
    }

    public static function int(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Int);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Percent);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::Date);
    }

    public static function string(string $key, string $label): self
    {
        return new self($key, $label, ReportColumnType::String);
    }

    /**
     * @return array{key: string, label: string, type: string, currency: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'currency' => $this->type === ReportColumnType::Money
                ? strtoupper(trim((string) $this->currency))
                : null,
        ];
    }
}
