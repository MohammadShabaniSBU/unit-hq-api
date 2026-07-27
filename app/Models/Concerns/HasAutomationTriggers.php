<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Events\ModelCreated;
use App\Events\ModelDeleted;
use App\Events\ModelUpdated;
use App\Support\Automation\AutomationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches generic Model* events for the automation matcher.
 * Skips entirely when AutomationContext is active (automation-originated writes).
 * Captures causer at dispatch time — queue workers have no auth.
 */
trait HasAutomationTriggers
{
    public static function bootHasAutomationTriggers(): void
    {
        static::created(function (Model $model): void {
            self::dispatchAutomationEvent($model, ModelCreated::class, []);
        });

        static::updated(function (Model $model): void {
            $dirty = [];
            foreach ($model->getChanges() as $key => $new) {
                if ($key === 'updated_at') {
                    continue;
                }
                $dirty[$key] = [
                    'old' => $model->getOriginal($key),
                    'new' => $new,
                ];
            }

            if ($dirty === []) {
                return;
            }

            self::dispatchAutomationEvent($model, ModelUpdated::class, $dirty);
        });

        static::deleted(function (Model $model): void {
            self::dispatchAutomationEvent($model, ModelDeleted::class, []);
        });
    }

    /**
     * @param  class-string<ModelCreated|ModelUpdated|ModelDeleted>  $eventClass
     * @param  array<string, array{old: mixed, new: mixed}>  $dirty
     */
    private static function dispatchAutomationEvent(Model $model, string $eventClass, array $dirty): void
    {
        if (AutomationContext::active()) {
            return;
        }

        $causer = auth()->user();
        $causerType = $causer?->getMorphClass();
        $causerId = $causer?->getKey();

        $subjectType = $model->getMorphClass();
        $subjectId = $model->getKey();
        $attributes = $model->attributesToArray();

        $dispatch = static function () use ($eventClass, $subjectType, $subjectId, $dirty, $attributes, $causerType, $causerId): void {
            event(new $eventClass(
                $subjectType,
                $subjectId,
                $dirty,
                $attributes,
                $causerType,
                $causerId,
            ));
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
