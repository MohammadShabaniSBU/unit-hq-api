<?php

declare(strict_types=1);

namespace App\Enums;

enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
    case Multiselect = 'multiselect';

    public function requiresOptions(): bool
    {
        return $this === self::Select || $this === self::Multiselect;
    }
}
