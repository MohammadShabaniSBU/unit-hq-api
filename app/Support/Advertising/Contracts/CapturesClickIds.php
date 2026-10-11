<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

interface CapturesClickIds
{
    /** @return list<string> */
    public function clickIdKeys(): array;
}
