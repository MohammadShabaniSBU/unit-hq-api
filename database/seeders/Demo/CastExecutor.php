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
                $index[(int) $day][] = $callable;
            }
        }
        ksort($index);
        $this->index = $index;

        return $this->index;
    }
}
