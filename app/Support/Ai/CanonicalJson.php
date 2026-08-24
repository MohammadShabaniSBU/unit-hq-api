<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Stable JSON for idempotency keys and propose-payload comparisons.
 * Recursive ksort on associative arrays; lists keep order.
 */
final class CanonicalJson
{
    /**
     * @param  array<string, mixed>|list<mixed>  $value
     */
    public static function encode(array $value): string
    {
        $canonical = json_encode(
            self::sortRecursive($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $canonical !== false ? $canonical : '';
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }
}
