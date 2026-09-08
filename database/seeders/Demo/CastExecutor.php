<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Database\Seeders\Demo\Journeys\AmaraOkafor;
use Database\Seeders\Demo\Journeys\BeaTorres;
use Database\Seeders\Demo\Journeys\DerekHoyle;
use Database\Seeders\Demo\Journeys\FrontDeskMisc;
use Database\Seeders\Demo\Journeys\GraceLin;
use Database\Seeders\Demo\Journeys\HannahCole;
use Database\Seeders\Demo\Journeys\IngridWeiss;
use Database\Seeders\Demo\Journeys\JeanLucPerrin;
use Database\Seeders\Demo\Journeys\Journey;
use Database\Seeders\Demo\Journeys\LuciaFerrer;
use Database\Seeders\Demo\Journeys\MarcusWebb;
use Database\Seeders\Demo\Journeys\NadiaRahal;
use Database\Seeders\Demo\Journeys\OmarHaddad;
use Database\Seeders\Demo\Journeys\PilarSantos;
use Database\Seeders\Demo\Journeys\RafaNunez;
use Database\Seeders\Demo\Journeys\SofiaMarin;
use Database\Seeders\Demo\Journeys\TheKellys;
use Database\Seeders\Demo\Journeys\TomBradley;
use Database\Seeders\Demo\Journeys\ViktorPalenik;
use InvalidArgumentException;

/**
 * Indexes cast journey scripts and runs due steps inside DemoClock ticks.
 */
final class CastExecutor
{
    public const SIM_START = '2025-06-01';

    public const SIM_END = '2026-07-31';

    /** Inclusive day count for `--compact` (start → start+9). */
    public const COMPACT_DAYS = 10;

    private static bool $compact = false;

    /** @var list<class-string<Journey>> */
    public const CAST = [
        MarcusWebb::class,
        LuciaFerrer::class,
        TomBradley::class,
        AmaraOkafor::class,
        JeanLucPerrin::class,
        SofiaMarin::class,
        DerekHoyle::class,
        PilarSantos::class,
        HannahCole::class,
        RafaNunez::class,
        IngridWeiss::class,
        OmarHaddad::class,
        GraceLin::class,
        BeaTorres::class,
        ViktorPalenik::class,
        NadiaRahal::class,
        TheKellys::class,
        FrontDeskMisc::class,
    ];

    /** @var array<int, list<callable(DemoWorld): void>>|null */
    private ?array $index = null;

    /**
     * @param  list<class-string<Journey>>|null  $journeys
     */
    public function __construct(
        private readonly ?array $journeys = null,
    ) {}

    public static function activateCompact(): void
    {
        self::$compact = true;
    }

    public static function resetWindow(): void
    {
        self::$compact = false;
    }

    public static function isCompact(): bool
    {
        return self::$compact;
    }

    /**
     * Active simulation end (compact window or the full-world constant).
     */
    public static function simEnd(): string
    {
        if (! self::$compact) {
            return self::SIM_END;
        }

        return CarbonImmutable::parse(self::SIM_START)
            ->addDays(self::COMPACT_DAYS - 1)
            ->toDateString();
    }

    /**
     * Offset of the last inclusive clock day (9 in compact, 425 in full).
     */
    public static function windowDays(): int
    {
        return (int) CarbonImmutable::parse(self::SIM_START)
            ->diffInDays(CarbonImmutable::parse(self::simEnd()));
    }

    /**
     * Full-world last-day offset. Journey scripts keep using this so
     * `--compact` can scale keys instead of collapsing them onto day 0.
     */
    public static function fullWindowDays(): int
    {
        return (int) CarbonImmutable::parse(self::SIM_START)
            ->diffInDays(CarbonImmutable::parse(self::SIM_END));
    }

    /**
     * Map a full-world day offset onto the active window.
     */
    public static function scaleDay(int $day): int
    {
        $max = self::windowDays();
        $full = self::fullWindowDays();

        if (! self::$compact || $max >= $full) {
            return $day;
        }

        if ($day <= 0) {
            return 0;
        }

        if ($day > $full) {
            return $max + ($day - $full);
        }

        return max(1, min($max, (int) round($day / $full * $max)));
    }

    /**
     * Civil date for a (full-world) day offset, scaled in compact mode.
     */
    public static function civilDate(int $offset): string
    {
        return CarbonImmutable::parse(self::SIM_START)
            ->addDays(self::scaleDay($offset))
            ->toDateString();
    }

    /**
     * @return list<class-string<Journey>>
     */
    public function journeyClasses(): array
    {
        return $this->journeys ?? self::CAST;
    }

    public function runDue(CarbonImmutable $date, DemoWorld $world, CarbonImmutable $start): void
    {
        $offset = (int) $start->diffInDays($date);
        foreach ($this->index()[$offset] ?? [] as $step) {
            $step($world);
        }
    }

    /**
     * Mini-clock for a single persona (smoke / debug).
     * Runs only from the persona's first scripted day through its last.
     *
     * @param  class-string<Journey>  $class
     * @return array{days: int, elapsed_ms: float}
     */
    public function runPersona(string $class, DemoWorld $world, ?CarbonImmutable $simStart = null): array
    {
        if (! is_subclass_of($class, Journey::class)) {
            throw new InvalidArgumentException("{$class} is not a Journey.");
        }

        $simStart ??= CarbonImmutable::parse(self::SIM_START)->startOfDay();
        $keys = array_keys($class::script());
        $minDay = $keys === [] ? 0 : (int) min($keys);
        $maxDay = $class::maxDay();

        DemoHttpFakes::install();

        $executor = new self([$class]);
        $clock = new DemoClock;

        return $clock->run(
            $simStart->addDays($minDay),
            $simStart->addDays($maxDay),
            $world,
            function (CarbonImmutable $date, DemoWorld $w) use ($executor, $simStart): void {
                $executor->runDue($date, $w, $simStart);
            },
        );
    }

    /**
     * @return array<int, list<callable(DemoWorld): void>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        foreach ($this->journeyClasses() as $class) {
            foreach ($class::script() as $day => $callable) {
                $index[self::scaleDay((int) $day)][] = $callable;
            }
        }
        ksort($index);
        $this->index = $index;

        return $this->index;
    }
}
