<?php

declare(strict_types=1);

namespace App\Support\Country;

interface TaxIdValidator
{
    public function validate(string $value): bool;

    public function hint(): string;
}
