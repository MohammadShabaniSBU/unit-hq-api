<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Crowd;

use App\Enums\ContactLifecycleStatus;
use App\Enums\DealStatus;
use App\Enums\DiscountKind;
use App\Models\Discount;
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
    public const SITE_HANDLES = ['madrid', 'norte', 'sur', 'este', 'oeste'];

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
     * Enrolment day. `$band` shapes occupancy: signers early, browsers late.
     *
     * @param  'early'|'mid'|'late'|'end'  $band
     */
    public static function enrolDay(DemoRng $rng, int $minTenureDays = 0, string $band = 'mid'): int
    {
        $span = self::simSpanDays();
        $earliest = 20;
        $latest = max($earliest, $span - max(14, $minTenureDays) - 7);

        return match ($band) {
            'early' => $rng->gaussInt(
                (int) (($earliest + min(160, $latest)) / 2),
                45,
                $earliest,
                min($latest, 220),
            ),
            'late' => $rng->gaussInt(
                min($span - 25, 340),
                35,
                max(220, $span - 140),
                $span - 5,
            ),
            'end' => $rng->int(max(0, $span - 12), max(1, $span - 2)),
            default => $rng->gaussInt(
                (int) (($earliest + min(300, $latest)) / 2),
                70,
                $earliest,
                $latest,
            ),
        };
    }

    public static function dateOn(int $dayOffset): string
    {
        return self::simStart()->addDays($dayOffset)->toDateString();
    }

    public static function pickSite(DemoWorld $world, DemoRng $rng): Site
    {
        return $world->site($rng->pick(self::SITE_HANDLES));
    }

    /**
     * Pick a seeded catalogue discount for crowd variety (DISC-02).
     *
     * @return array{discount_id: int, commitment_weeks: int|null}|null
     */
    public static function pickDiscount(DemoRng $rng): ?array
    {
        if ($rng->bool(0.55)) {
            $named = Discount::query()
                ->where('kind', DiscountKind::Percent)
                ->whereIn('name', ['10% off', '20% off'])
                ->orderBy('id')
                ->get();
            if ($named->isEmpty()) {
                return null;
            }
            $discount = $named[$rng->int(0, $named->count() - 1)];

            return [
                'discount_id' => (int) $discount->id,
                'commitment_weeks' => null,
            ];
        }

        $discount = Discount::query()
            ->where('kind', DiscountKind::FreeTime)
            ->where('name', 'Long-stay promo')
            ->orderBy('id')
            ->first();

        if ($discount === null) {
            return null;
        }

        return [
            'discount_id' => (int) $discount->id,
            'commitment_weeks' => (int) $rng->pick([4, 8, 12]),
        ];
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
        $first = fake('es_ES')->firstName();
        $last = fake('es_ES')->lastName();
        $email = strtolower(str_replace('_', '.', $handle)).'.'.substr((string) $rng->int(1000, 9999), 0).'@demo.keevaris.test';

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
