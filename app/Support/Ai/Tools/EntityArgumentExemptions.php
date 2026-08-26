<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

/**
 * Schema `*_id` keys that are not EntityType cases.
 *
 * `unit_class_rate_id` is still gated: ArgumentProvenance resolves the junction
 * to a licensed unit_class, and CatalogueLinePricer asserts the same.
 * `price_id` / `quoted_price_id` / `quoted_tax_rate_id` are catalogue continuity
 * tokens, not EntityRef types. `offer_option_id` is not an entity argument.
 */
final class EntityArgumentExemptions
{
    /** @var list<string> */
    public const KEYS = [
        'unit_class_rate_id',
        'price_id',
        'quoted_price_id',
        'quoted_tax_rate_id',
        'offer_option_id',
        'message_thread_id',
        'agent_conversation_message_id',
    ];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
