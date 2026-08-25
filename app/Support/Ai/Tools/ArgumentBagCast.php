<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>|null>
 */
final class ArgumentBagCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return ArgumentBag::normalise($value);
        }

        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return ArgumentBag::normalise($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return ArgumentBag::encode(ArgumentBag::normalise($value));
    }
}
