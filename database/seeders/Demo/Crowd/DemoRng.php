<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd;

/**
 * Deterministic RNG for crowd generation. Seeded from DEMO_SEED (default 424242).
 */
final class DemoRng
{
    public function __construct(private int $seed)
    {
        $this->reseed();
    }

    public static function fromEnv(): self
    {
        return new self((int) (env('DEMO_SEED', env('SEED_RNG', 424242))));
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function reseed(): void
    {
        mt_srand($this->seed);
        fake()->seed($this->seed);
    }

    public function int(int $min, int $max): int
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return mt_rand($min, $max);
    }

    public function float(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    public function bool(float $probability = 0.5): bool
    {
        return $this->float() < $probability;
    }

    /**
     * @param  array<array-key, float|int>  $weights  key => weight
     */
    public function pickWeighted(array $weights): int|string
    {
        $total = 0.0;
        foreach ($weights as $weight) {
            $total += (float) $weight;
        }
        if ($total <= 0.0) {
            throw new \InvalidArgumentException('pickWeighted requires positive total weight.');
        }

        $cursor = $this->float() * $total;
        foreach ($weights as $key => $weight) {
            $cursor -= (float) $weight;
            if ($cursor <= 0.0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * Approximate integer from a triangular / clamped normal around $mean.
     */
    public function gaussInt(int $mean, int $spread, int $min, int $max): int
    {
        $u1 = max(1e-9, $this->float());
        $u2 = $this->float();
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
        $value = (int) round($mean + $z * $spread);

        return max($min, min($max, $value));
    }

    /**
     * @template T
     * @param  non-empty-list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }
}
