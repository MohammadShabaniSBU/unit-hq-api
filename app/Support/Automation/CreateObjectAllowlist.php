<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * Object types permitted for action.create_object (v1).
 */
final class CreateObjectAllowlist
{
    /** @var list<string> */
    public const TYPES = ['contact', 'deal', 'task', 'note'];

    public static function contains(string $objectType): bool
    {
        return in_array($objectType, self::TYPES, true);
    }

    public static function supportedList(): string
    {
        return implode(', ', self::TYPES);
    }
}
