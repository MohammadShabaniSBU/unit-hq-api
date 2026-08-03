<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd;

use Database\Seeders\Demo\Crowd\Archetypes\BrowserCompiler;
use Database\Seeders\Demo\Crowd\Archetypes\ChurnerCompiler;
use Database\Seeders\Demo\Crowd\Archetypes\ConsideredSignerCompiler;
use Database\Seeders\Demo\Crowd\Archetypes\QuickSignerCompiler;
use Database\Seeders\Demo\Crowd\Archetypes\SeriousDelinquentCompiler;
use Database\Seeders\Demo\Crowd\Archetypes\SlowPayerCompiler;
use Database\Seeders\Demo\Crowd\Archetypes\UpsizerDownsizerCompiler;
use Database\Seeders\Demo\DemoWorld;

/**
 * Compiles weighted crowd archetypes into day-script maps (same shape as cast journeys).
 */
final class CrowdGenerator
{
    public function __construct(
        private readonly DemoRng $rng = new DemoRng(424242),
    ) {}

    public static function fromEnv(): self
    {
        return new self(DemoRng::fromEnv());
    }

    /**
     * @return list<array<int, callable(DemoWorld): void>>
     */
    public function compile(DemoWorld $world): array
    {
        $this->rng->reseed();
        $scripts = [];
        $seq = 0;

        foreach (Archetype::cases() as $archetype) {
            $count = $archetype->targetCount();
            for ($i = 0; $i < $count; $i++) {
                $handle = 'crowd_'.$seq;
                $seq++;
                $scripts[] = $this->compileOne($archetype, $handle, $i, $count);
                $world->remember("{$handle}.archetype", $archetype->value);
            }
        }

        $world->remember('crowd.count', $seq);
        $world->remember('crowd.seed', $this->rng->seed());

        return $scripts;
    }

    /**
     * @return array<int, callable(DemoWorld): void>
     */
    private function compileOne(Archetype $archetype, string $handle, int $index, int $count): array
    {
        return match ($archetype) {
            Archetype::Browser => BrowserCompiler::compile($handle, $this->rng),
            Archetype::QuickSigner => QuickSignerCompiler::compile(
                $handle,
                $this->rng,
                withRateChange: $index < 17, // ~17 applied historical rate changes
            ),
            Archetype::ConsideredSigner => ConsideredSignerCompiler::compile(
                $handle,
                $this->rng,
                withScheduledRate: $index < 3, // ~3 scheduled ahead of seed-end
            ),
            Archetype::SlowPayer => SlowPayerCompiler::compile($handle, $this->rng),
            Archetype::SeriousDelinquent => SeriousDelinquentCompiler::compile(
                $handle,
                $this->rng,
                path: match (true) {
                    $index === 0 => 'vacate',
                    $index <= 2 => 'overlock',
                    default => 'deep',
                },
                // Stagger open-book ageing so every bucket is non-empty at seed-end.
                targetDaysOverdue: match ($index) {
                    0 => 90,   // vacate path (historical deep)
                    1, 2 => 22, // overlock ~15-30
                    3 => 70,   // 60+
                    4 => 45,   // 31-60
                    5 => 20,   // 15-30
                    6 => 10,   // 8-14
                    default => 4, // 1-7
                },
            ),
            Archetype::Churner => ChurnerCompiler::compile($handle, $this->rng),
            Archetype::UpsizerDownsizer => UpsizerDownsizerCompiler::compile($handle, $this->rng),
        };
    }
}
