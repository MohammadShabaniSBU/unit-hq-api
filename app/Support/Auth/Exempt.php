<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Explicit RoutePermissions exemption. Only self and reference are legitimate.
 */
final class Exempt
{
    public const CATEGORY_SELF = 'self';

    public const CATEGORY_REFERENCE = 'reference';

    private function __construct(
        public readonly string $category,
        public readonly string $reason,
    ) {}

    public static function self(string $reason): self
    {
        return new self(self::CATEGORY_SELF, $reason);
    }

    public static function reference(string $reason): self
    {
        return new self(self::CATEGORY_REFERENCE, $reason);
    }
}
