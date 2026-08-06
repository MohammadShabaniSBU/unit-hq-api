<?php

declare(strict_types=1);

namespace App\Support\Insights\Contracts;

/**
 * Declared in S21-02; param discovery implemented in S21-05.
 */
interface DescribesResourceParams
{
    /**
     * @return list<array<string, mixed>>
     */
    public function resourceParams(string $kind, string $ref): array;
}
