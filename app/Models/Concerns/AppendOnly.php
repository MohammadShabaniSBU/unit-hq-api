<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Advertising\AppendOnlyException;
use Illuminate\Database\Eloquent\Model;

/**
 * Rejects delete, and update except what assertAppendOnlyUpdate allows.
 * Database triggers enforce the same rules for query-builder writes.
 */
trait AppendOnly
{
    abstract protected static function appendOnlyMarker(): string;

    abstract protected function assertAppendOnlyUpdate(): void;

    public static function bootAppendOnly(): void
    {
        static::updating(function (Model $model): void {
            $model->assertAppendOnlyUpdate();
        });

        static::deleting(function (): void {
            throw AppendOnlyException::immutable(static::appendOnlyMarker());
        });
    }
}
