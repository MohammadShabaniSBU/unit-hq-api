<?php

declare(strict_types=1);

namespace App\Enums;

enum AiProvider: string
{
    case Anthropic = 'anthropic';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic',
        };
    }
}
