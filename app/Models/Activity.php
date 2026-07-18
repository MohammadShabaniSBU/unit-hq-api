<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RequestId;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $requestId = RequestId::get();
            if ($requestId === null) {
                return;
            }

            $properties = $activity->properties?->toArray() ?? [];
            if (! array_key_exists('request_id', $properties)) {
                $properties['request_id'] = $requestId;
                $activity->properties = collect($properties);
            }
        });
    }
}
