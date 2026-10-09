<?php

declare(strict_types=1);

namespace App\Support\Communications;

/**
 * What a communication account may carry. `all` is the default and serves both
 * send classes. `transactional` is never used for marketing; `marketing` is
 * never used for transactional (M7: broadcast streams stay separate).
 */
enum AccountPurpose: string
{
    case All = 'all';
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    /**
     * Purposes eligible for a send class, in preference order.
     *
     * @return list<self>
     */
    public static function eligibleFor(SendClass $class): array
    {
        return match ($class) {
            SendClass::Marketing => [self::Marketing, self::All],
            SendClass::Transactional => [self::Transactional, self::All],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
