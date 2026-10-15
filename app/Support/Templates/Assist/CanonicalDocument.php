<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

use JsonException;
use RuntimeException;

/**
 * Stable JSON for template-assist hashes. Key order is sorted. Whitespace is omitted.
 */
final class CanonicalDocument
{
    /**
     * @param  array<string, mixed>  $document
     */
    public static function hash(array $document): string
    {
        return hash('sha256', self::encode($document));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public static function encode(array $document): string
    {
        try {
            return json_encode(self::sort($document), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Canonical JSON encode failed.', 0, $exception);
        }
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::sort($item);
        }

        return $value;
    }
}
