<?php

declare(strict_types=1);

namespace App\Support\Facility;

final class UnitCsvImportResult
{
    /**
     * @param  list<array{row: int, message: string}>  $errors
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly array $errors = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
