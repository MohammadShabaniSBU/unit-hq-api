<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * Job-/request-scoped flag: automation-originated writes must not re-enter the matcher.
 * Mirror of RequestId — set around handler model writes, checked by HasAutomationTriggers.
 */
final class AutomationContext
{
    private static ?int $runId = null;

    public static function active(): bool
    {
        return self::$runId !== null;
    }

    public static function runId(): ?int
    {
        return self::$runId;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(int $runId, callable $callback): mixed
    {
        $previous = self::$runId;
        self::$runId = $runId;

        try {
            return $callback();
        } finally {
            self::$runId = $previous;
        }
    }

    public static function clear(): void
    {
        self::$runId = null;
    }
}
