<?php

declare(strict_types=1);

namespace App\Support\Insights\Contracts;

/**
 * Declared in S21-02; listing implemented in S21-05.
 */
interface ListsResources
{
    /**
     * @return list<array<string, mixed>>
     */
    public function resources(string $kind): array;
}
