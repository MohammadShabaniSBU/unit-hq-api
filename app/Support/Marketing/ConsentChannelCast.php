<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Support\Communications\Channel;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a ConsentChannel. Accepts a comms Channel on write so existing
 * callers keep passing Channel::Email until task 03.
 *
 * @implements CastsAttributes<ConsentChannel, Channel|ConsentChannel|string|null>
 */
final class ConsentChannelCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ConsentChannel
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof ConsentChannel) {
            return $value;
        }

        return ConsentChannel::from((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Channel) {
            return ConsentChannel::fromComms($value)->value;
        }

        if ($value instanceof ConsentChannel) {
            return $value->value;
        }

        return ConsentChannel::from((string) $value)->value;
    }
}
