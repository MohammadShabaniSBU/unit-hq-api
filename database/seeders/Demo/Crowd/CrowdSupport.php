<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd;

use App\Enums\ContactLifecycleStatus;
use App\Enums\DealStatus;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\CastExecutor;
use Database\Seeders\Demo\DemoWorld;
use Database\Seeders\Demo\Journeys\JourneySupport;
use RuntimeException;

/**
 * Shared helpers for archetype compilers.
 */
final class CrowdSupport
{
    /** @var list<string> */
    public const SITE_HANDLES = ['madrid', 'barcelona', 'valencia', 'london', 'paris'];

    /** @var list<string> */
    public const UNIT_CLASSES = [
        'SS1', 'SS2', 'SS3', 'SS4', 'SS5', 'SS6', 'SS7', 'SS8',
        'AL1', 'AL2', 'AL3', 'AL4',
    ];

    public static function simStart(): CarbonImmutable
    {
        return CarbonImmutable::parse(CastExecutor::SIM_START)->startOfDay();
    }

    public static function simEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse(CastExecutor::SIM_END)->startOfDay();
    }

    public static function simSpanDays(): int
    {
        return (int) self::simStart()->diffInDays(self::simEnd());
    }

    /**
     * Enrolment weighted toward months 2–10 of the 14-month window.
     */
    public static function enrolDay(DemoRng $rng, int $minTenureDays = 0): int
    {
        $span = self::simSpanDays();
        $earliest = 30;
        $latest = max($earliest, $span - max(14, $minTenureDays) - 7);

        // Triangular-ish: peak mid-window (months 2–10 ≈ days 30–300).
        $peak = (int) (($earliest + min(300, $latest)) / 2);

        return $rng->gaussInt($peak, 70, $earliest, $latest);
    }

    public static function dateOn(int $dayOffset): string
    {
        return self::simStart()->addDays($dayOffset)->toDateString();
    }

    public static function pickSite(DemoWorld $world, DemoRng $rng): Site
    {
        return $world->site($rng->pick(self::SITE_HANDLES));
    }

    public static function vacantUnit(Site $site, DemoRng $rng): Unit
    {
        $classes = self::UNIT_CLASSES;
        // Shuffle deterministically via RNG picks without mutating shared const.
        $order = [];
        $pool = $classes;
        while ($pool !== []) {
            $idx = $rng->int(0, count($pool) - 1);
            $order[] = $pool[$idx];
            array_splice($pool, $idx, 1);
        }

        foreach ($order as $code) {
            if (! UnitClass::query()->where('code', $code)->exists()) {
                continue;
            }
            try {
                return JourneySupport::vacantUnit($site, $code);
            } catch (RuntimeException) {
                continue;
            }
        }

        // Last resort: any class at site.
        $all = UnitClass::query()->orderBy('code')->pluck('code');
        foreach ($all as $code) {
            try {
                return JourneySupport::vacantUnit($site, (string) $code);
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException("No vacant unit at site {$site->code} for crowd enrolment.");
    }

    /**
     * @return array{first: string, last: string, email: string}
     */
    public static function person(DemoRng $rng, string $handle): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $email = strtolower(str_replace('_', '.', $handle)).'.'.substr((string) $rng->int(1000, 9999), 0).'@demo.unit-hq.test';

        return [
            'first' => $first,
            'last' => $last,
            'email' => $email,
        ];
    }

    public static function createCrowdContact(DemoWorld $world, string $handle, DemoRng $rng, array $attrs = []): void
    {
        $person = self::person($rng, $handle);
        JourneySupport::createContact($world, $handle, $person['first'], $person['last'], array_merge([
            'email' => $person['email'],
            'source_detail' => 'demo_crowd',
            'status' => ContactLifecycleStatus::Prospect,
        ], $attrs));
    }

    public static function markLost(DemoWorld $world, string $handle): void
    {
        $contact = $world->contact("{$handle}.contact");
        $contact->forceFill(['status' => ContactLifecycleStatus::Lost])->save();
        if ($world->has("{$handle}.deal")) {
            $deal = $world->get("{$handle}.deal");
            $deal->forceFill(['status' => DealStatus::ClosedLost])->save();
        }
    }

    public static function markUnresponsive(DemoWorld $world, string $handle): void
    {
        $contact = $world->contact("{$handle}.contact");
        if ($contact->status === ContactLifecycleStatus::Prospect
            || $contact->status === ContactLifecycleStatus::Lead) {
            // leave as lead/prospect — quiet browser
        }
        if ($world->has("{$handle}.deal")) {
            $deal = $world->get("{$handle}.deal");
            $deal->forceFill(['status' => DealStatus::Unresponsive])->save();
        }
    }

    /**
     * Merge day→callable maps; multiple callables on same day run in order.
     *
     * @param  array<int, callable(DemoWorld): void>  ...$maps
     * @return array<int, callable(DemoWorld): void>
     */
    public static function mergeScripts(array ...$maps): array
    {
        /** @var array<int, list<callable(DemoWorld): void>> $buckets */
        $buckets = [];
        foreach ($maps as $map) {
            foreach ($map as $day => $fn) {
                $buckets[(int) $day][] = $fn;
            }
        }

        $merged = [];
        foreach ($buckets as $day => $fns) {
            $merged[$day] = static function (DemoWorld $world) use ($fns): void {
                foreach ($fns as $fn) {
                    $fn($world);
                }
            };
        }
        ksort($merged);

        return $merged;
    }
}
