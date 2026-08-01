<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LogChannel;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

/**
 * Thin helper so every manual activity row gets request_id and a LogChannel.
 */
final class RecordsActivity
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        LogChannel $channel,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?Model $causer = null,
        bool $anonymous = false,
    ): ?ActivityContract {
        $properties = array_merge($properties, [
            'request_id' => RequestId::get(),
        ]);

        $logger = activity($channel->value)
            ->withProperties($properties)
            ->event($description);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($anonymous) {
            $logger->causedByAnonymous();
        } elseif ($causer !== null) {
            $logger->causedBy($causer);
        }

        return $logger->log($description);
    }

    /**
     * Convenience for tier-3 core events.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function core(
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?Model $causer = null,
        bool $anonymous = false,
    ): ?ActivityContract {
        return self::log(LogChannel::Core, $description, $subject, $properties, $causer, $anonymous);
    }
}
