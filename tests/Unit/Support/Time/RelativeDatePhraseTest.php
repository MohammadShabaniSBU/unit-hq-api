<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Time;

use App\Support\Time\RelativeDatePhrase;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RelativeDatePhraseTest extends TestCase
{
    #[DataProvider('phrasesOnWednesday')]
    public function test_resolves_phrases_on_wednesday_26_august(string $phrase, string $iso): void
    {
        $today = CarbonImmutable::parse('2026-08-26')->startOfDay();

        $this->assertSame($iso, RelativeDatePhrase::resolve($phrase, $today)?->toDateString());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phrasesOnWednesday(): array
    {
        return [
            'today en' => ['today', '2026-08-26'],
            'today es' => ['hoy', '2026-08-26'],
            'today fr' => ["aujourd'hui", '2026-08-26'],
            'tomorrow en' => ['tomorrow', '2026-08-27'],
            'tomorrow es' => ['mañana', '2026-08-27'],
            'tomorrow fr' => ['demain', '2026-08-27'],
            'day after en' => ['day after tomorrow', '2026-08-28'],
            'day after es' => ['pasado mañana', '2026-08-28'],
            'day after fr' => ['après-demain', '2026-08-28'],
            'next monday en' => ['next Monday', '2026-08-31'],
            'next monday es' => ['próximo lunes', '2026-08-31'],
            'next monday es viene' => ['lunes que viene', '2026-08-31'],
            'next monday fr' => ['lundi prochain', '2026-08-31'],
            'this wednesday en' => ['this Wednesday', '2026-08-26'],
            'this wednesday es' => ['este miércoles', '2026-08-26'],
            'this wednesday fr' => ['ce mercredi', '2026-08-26'],
            'in 3 days en' => ['in 3 days', '2026-08-29'],
            'in 3 days es' => ['en 3 días', '2026-08-29'],
            'in 3 days fr' => ['dans 3 jours', '2026-08-29'],
            'in 2 weeks en' => ['in 2 weeks', '2026-09-09'],
            'in 2 weeks es' => ['en 2 semanas', '2026-09-09'],
            'in 2 weeks fr' => ['dans 2 semaines', '2026-09-09'],
            '15 january rollover en' => ['15 January', '2027-01-15'],
            '15 january rollover of' => ['15th of January', '2027-01-15'],
            '15 january rollover es' => ['15 de enero', '2027-01-15'],
            '15 january rollover fr' => ['le 15 janvier', '2027-01-15'],
            'month day' => ['January 15', '2027-01-15'],
            'day slash month' => ['15/01', '2027-01-15'],
            'iso passthrough' => ['2026-08-31', '2026-08-31'],
            'iso past passthrough' => ['2025-07-21', '2025-07-21'],
            'start of next month' => ['start of next month', '2026-09-01'],
            'start of next month es' => ['inicio del próximo mes', '2026-09-01'],
            'start of next month fr' => ['début du mois prochain', '2026-09-01'],
            'end of the month' => ['end of the month', '2026-08-31'],
            'end of the month es' => ['fin de mes', '2026-08-31'],
            'end of the month fr' => ['fin du mois', '2026-08-31'],
        ];
    }

    #[Test]
    public function next_weekday_on_that_weekday_is_plus_seven(): void
    {
        $monday = CarbonImmutable::parse('2026-08-31')->startOfDay();

        $this->assertSame('2026-09-07', RelativeDatePhrase::resolve('next Monday', $monday)?->toDateString());
        $this->assertSame('2026-09-07', RelativeDatePhrase::resolve('próximo lunes', $monday)?->toDateString());
        $this->assertSame('2026-09-07', RelativeDatePhrase::resolve('lundi prochain', $monday)?->toDateString());
    }

    #[Test]
    public function this_weekday_on_that_weekday_is_today(): void
    {
        $monday = CarbonImmutable::parse('2026-08-31')->startOfDay();

        $this->assertSame('2026-08-31', RelativeDatePhrase::resolve('this Monday', $monday)?->toDateString());
        $this->assertSame('2026-08-31', RelativeDatePhrase::resolve('este lunes', $monday)?->toDateString());
        $this->assertSame('2026-08-31', RelativeDatePhrase::resolve('ce lundi', $monday)?->toDateString());
    }

    #[Test]
    public function in_one_month_from_the_31st_clamps_to_month_end(): void
    {
        $today = CarbonImmutable::parse('2026-01-31')->startOfDay();

        $this->assertSame('2026-02-28', RelativeDatePhrase::resolve('in 1 month', $today)?->toDateString());
        $this->assertSame('2026-02-28', RelativeDatePhrase::resolve('en 1 mes', $today)?->toDateString());
        $this->assertSame('2026-02-28', RelativeDatePhrase::resolve('dans 1 mois', $today)?->toDateString());
    }

    #[Test]
    public function garbage_phrase_returns_null(): void
    {
        $today = CarbonImmutable::parse('2026-08-26')->startOfDay();

        $this->assertNull(RelativeDatePhrase::resolve('sometime soon-ish', $today));
        $this->assertNull(RelativeDatePhrase::resolve('between the 1st and the 5th', $today));
        $this->assertNull(RelativeDatePhrase::resolve('Monday morning', $today));
        $this->assertNull(RelativeDatePhrase::resolve('', $today));
    }
}
