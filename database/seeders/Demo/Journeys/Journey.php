<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Journeys;

use Database\Seeders\Demo\DemoWorld;

/**
 * Day-scripted persona journey. Days are offsets from simulation start.
 *
 * @phpstan-type ScriptMap array<int, callable(DemoWorld): void>
 */
abstract class Journey
{
    abstract public static function handle(): string;

    /**
     * @return ScriptMap
     */
    abstract public static function script(): array;

    abstract public static function assertEndState(DemoWorld $world): void;

    public static function maxDay(): int
    {
        $days = array_keys(static::script());

        return $days === [] ? 0 : (int) max($days);
    }
}
