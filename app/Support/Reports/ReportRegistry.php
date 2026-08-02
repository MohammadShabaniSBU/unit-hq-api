<?php

declare(strict_types=1);

namespace App\Support\Reports;

use InvalidArgumentException;

/**
 * name → report class. Controllers resolve through here so S16 reports
 * register in one place.
 */
final class ReportRegistry
{
    /**
     * @var array<string, class-string<AbstractReport>>
     */
    private const REPORTS = [
        'demo' => DemoReport::class,
    ];

    /**
     * @return array<string, class-string<AbstractReport>>
     */
    public static function all(): array
    {
        return self::REPORTS;
    }

    public static function has(string $name): bool
    {
        return isset(self::REPORTS[$name]);
    }

    public static function make(string $name): AbstractReport
    {
        if (! self::has($name)) {
            throw new InvalidArgumentException("Unknown report [{$name}].");
        }

        $class = self::REPORTS[$name];

        return new $class;
    }

    /**
     * @return list<class-string<AbstractReport>>
     */
    public static function classes(): array
    {
        return array_values(self::REPORTS);
    }
}
