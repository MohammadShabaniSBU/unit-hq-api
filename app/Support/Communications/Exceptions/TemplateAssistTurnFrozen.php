<?php

declare(strict_types=1);

namespace App\Support\Communications\Exceptions;

use RuntimeException;

final class TemplateAssistTurnFrozen extends RuntimeException
{
    public static function column(string $column): self
    {
        return new self("Template assist turn column {$column} is frozen.");
    }

    public static function transition(string $from, string $to): self
    {
        return new self("Template assist turn cannot move from {$from} to {$to}.");
    }
}
