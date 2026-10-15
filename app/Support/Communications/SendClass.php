<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\TemplatePurpose;

enum SendClass: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public static function forTemplatePurpose(TemplatePurpose $purpose): self
    {
        return match ($purpose) {
            TemplatePurpose::Marketing, TemplatePurpose::Newsletter => self::Marketing,
            default => self::Transactional,
        };
    }
}
