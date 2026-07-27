<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Models\Automation;
use Illuminate\Support\Facades\Cache;

/**
 * Cheap early-exit cache: which active automations watch (objectType, triggerType).
 */
final class AutomationWatchCache
{
    public static function key(string $objectType, AutomationNodeType $triggerType): string
    {
        return "automation_watch:{$objectType}:{$triggerType->value}";
    }

    /** @return list<int> */
    public static function automationIds(string $objectType, AutomationNodeType $triggerType): array
    {
        return Cache::remember(self::key($objectType, $triggerType), 300, function () use ($objectType, $triggerType): array {
            return Automation::query()
                ->where('status', AutomationStatus::Active)
                ->whereNull('archived_at')
                ->whereHas('nodes', function ($q) use ($objectType, $triggerType): void {
                    $q->where('type', $triggerType->value)
                        ->where(function ($inner) use ($objectType): void {
                            $inner->where('config->objectType', $objectType)
                                ->orWhere('config->object_type', $objectType);
                        });
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });
    }

    public static function forget(string $objectType, AutomationNodeType $triggerType): void
    {
        Cache::forget(self::key($objectType, $triggerType));
    }

    public static function flushAll(): void
    {
        foreach ([
            AutomationNodeType::ObjectCreated,
            AutomationNodeType::ObjectUpdated,
        ] as $triggerType) {
            foreach (['contact', 'deal', 'offer', 'reservation', 'unit', 'contract', 'insurance'] as $objectType) {
                self::forget($objectType, $triggerType);
            }
        }
    }
}
