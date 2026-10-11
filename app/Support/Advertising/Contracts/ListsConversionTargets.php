<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

interface ListsConversionTargets
{
    /**
     * @return list<array{ref: string, name: string, kind: string}>
     */
    public function conversionTargets(): array;
}
