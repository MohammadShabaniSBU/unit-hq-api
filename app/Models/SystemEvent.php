<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Auth\Actor;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tier-1 operational event. Append-only; high-frequency noise kept out of activity_log.
 *
 * @property int         $id
 * @property string      $event
 * @property string|null $request_id
 * @property string|null $subject_type
 * @property int|null    $subject_id
 * @property string|null $causer_type
 * @property int|null    $causer_id
 * @property array|null  $payload
 * @property \Illuminate\Support\Carbon $created_at
 */
class SystemEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event',
        'request_id',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record a tier-1 event. Never throws into the caller.
     * When inside a DB transaction, insert runs after commit.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(string $event, ?Model $subject = null, array $payload = []): void
    {
        $write = function () use ($event, $subject, $payload): void {
            try {
                $actor = Actor::current();
                $causer = $actor instanceof Employee ? $actor : null;

                static::query()->create([
                    'event' => $event,
                    'request_id' => RequestId::get(),
                    'subject_type' => $subject?->getMorphClass(),
                    'subject_id' => $subject?->getKey(),
                    'causer_type' => $causer?->getMorphClass(),
                    'causer_id' => $causer?->getKey(),
                    'payload' => $payload === [] ? null : $payload,
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($write);

            return;
        }

        $write();
    }
}
