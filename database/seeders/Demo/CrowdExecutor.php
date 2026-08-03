<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;

/**
 * Indexes compiled crowd scripts and runs due steps inside DemoClock ticks.
 */
final class CrowdExecutor
{
    /** @var array<int, list<callable(DemoWorld): void>> */
    private array $index = [];

    /**
     * @param  list<array<int, callable(DemoWorld): void>>  $scripts
     */
    public function __construct(array $scripts = [])
    {
        foreach ($scripts as $script) {
            foreach ($script as $day => $callable) {
                $this->index[(int) $day][] = $callable;
            }
        }
        ksort($this->index);
    }

    public function stepCount(): int
    {
        $count = 0;
        foreach ($this->index as $steps) {
            $count += count($steps);
        }

        return $count;
    }

    public function runDue(CarbonImmutable $date, DemoWorld $world, CarbonImmutable $start): void
    {
        $offset = (int) $start->diffInDays($date);
        foreach ($this->index[$offset] ?? [] as $step) {
            $step($world);
        }
    }
}
