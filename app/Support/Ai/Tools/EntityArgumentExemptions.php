<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

/**
 * Schema `*_id` keys that are not EntityType cases.
 *
 * `unit_class_rate_id` is still gated: ArgumentProvenance resolves the junction
 * to a licensed unit_class, and CatalogueLinePricer asserts the same.
 * `price_id` and `offer_option_id` wait for S25-03.
 */
final class EntityArgumentExemptions
{
    /** @var list<string> */
    public const KEYS = [
        'unit_class_rate_id',
        'price_id',
        'offer_option_id',
    ];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
