<?php

declare(strict_types=1);

namespace App\Support\Reports\Charts;

use App\Support\Billing\BillingMath;
use InvalidArgumentException;

/**
 * One plotted series. Values are display-only floats and are never read
 * back into the ledger. bcmath strings are rounded to 2dp at this edge.
 */
final readonly class ChartSeries
{
    /**
     * @param  list<int|float|null>  $data
     */
    public function __construct(
        public array $data,
        public ?string $nameKey,
        public ?string $name,
        public ?ChartSeriesKind $kind = null,
        public int $axis = 0,
    ) {
        $hasKey = $this->nameKey !== null && $this->nameKey !== '';
        $hasName = $this->name !== null && $this->name !== '';

        if ($hasKey === $hasName) {
            throw new InvalidArgumentException('ChartSeries requires exactly one of nameKey or name.');
        }

        if ($this->axis !== 0 && $this->axis !== 1) {
            throw new InvalidArgumentException('ChartSeries axis must be 0 or 1.');
        }
    }

    /**
     * @param  list<int|float|string|null>  $data
     */
    public static function keyed(
        string $nameKey,
        array $data,
        ?ChartSeriesKind $kind = null,
        int $axis = 0,
    ): self {
        return new self(self::normalize($data), $nameKey, null, $kind, $axis);
    }

    /**
     * @param  list<int|float|string|null>  $data
     */
    public static function named(
        string $name,
        array $data,
        ?ChartSeriesKind $kind = null,
        int $axis = 0,
    ): self {
        return new self(self::normalize($data), null, $name, $kind, $axis);
    }

    /**
     * @return array{name_key: string|null, name: string|null, data: list<int|float|null>, kind: string|null, axis: int}
     */
    public function toArray(): array
    {
        return [
            'name_key' => $this->nameKey,
            'name' => $this->name,
            'data' => $this->data,
            'kind' => $this->kind?->value,
            'axis' => $this->axis,
        ];
    }

    /**
     * @param  list<int|float|string|null>  $data
     * @return list<int|float|null>
     */
    private static function normalize(array $data): array
    {
        $out = [];
        foreach ($data as $value) {
            if ($value === null) {
                $out[] = null;

                continue;
            }

            if (is_int($value)) {
                $out[] = $value;

                continue;
            }

            if (is_float($value)) {
                $out[] = round($value, 2);

                continue;
            }

            $out[] = (float) BillingMath::round2($value);
        }

        return $out;
    }
}
