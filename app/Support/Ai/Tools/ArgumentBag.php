<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

/**
 * Single normaliser for tool argument bags. Empty bags are objects, not lists.
 */
final class ArgumentBag
{
    /**
     * @return array<string, mixed>
     */
    public static function normalise(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if ($raw instanceof \stdClass) {
            $raw = (array) $raw;
        }

        if (! is_array($raw)) {
            return [];
        }

        if (array_is_list($raw)) {
            return [];
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    public static function encode(array $bag): string
    {
        if ($bag === []) {
            return '{}';
        }

        $json = json_encode($bag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{}';
    }

    /**
     * Shape suitable for json_encode: empty bag becomes {}.
     *
     * @param  array<string, mixed>  $bag
     */
    public static function jsonReady(array $bag): array|\stdClass
    {
        return $bag === [] ? new \stdClass : $bag;
    }
}
