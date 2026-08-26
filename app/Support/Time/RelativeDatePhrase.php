<?php

declare(strict_types=1);

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Deterministic relative-date parser for en/es/fr. Never guesses: unknown
 * phrases return null. Accent-folded and locale-agnostic so a Spanish phrase
 * in an English conversation still resolves.
 */
final class RelativeDatePhrase
{
    /** @var array<string, int> */
    private const WEEKDAYS = [
        'monday' => CarbonInterface::MONDAY,
        'tuesday' => CarbonInterface::TUESDAY,
        'wednesday' => CarbonInterface::WEDNESDAY,
        'thursday' => CarbonInterface::THURSDAY,
        'friday' => CarbonInterface::FRIDAY,
        'saturday' => CarbonInterface::SATURDAY,
        'sunday' => CarbonInterface::SUNDAY,
        'lunes' => CarbonInterface::MONDAY,
        'martes' => CarbonInterface::TUESDAY,
        'miercoles' => CarbonInterface::WEDNESDAY,
        'jueves' => CarbonInterface::THURSDAY,
        'viernes' => CarbonInterface::FRIDAY,
        'sabado' => CarbonInterface::SATURDAY,
        'domingo' => CarbonInterface::SUNDAY,
        'lundi' => CarbonInterface::MONDAY,
        'mardi' => CarbonInterface::TUESDAY,
        'mercredi' => CarbonInterface::WEDNESDAY,
        'jeudi' => CarbonInterface::THURSDAY,
        'vendredi' => CarbonInterface::FRIDAY,
        'samedi' => CarbonInterface::SATURDAY,
        'dimanche' => CarbonInterface::SUNDAY,
    ];

    /** @var array<string, int> */
    private const MONTHS = [
        'january' => 1,
        'february' => 2,
        'march' => 3,
        'april' => 4,
        'may' => 5,
        'june' => 6,
        'july' => 7,
        'august' => 8,
        'september' => 9,
        'october' => 10,
        'november' => 11,
        'december' => 12,
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
        'janvier' => 1,
        'fevrier' => 2,
        'mars' => 3,
        'avril' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7,
        'aout' => 8,
        'septembre' => 9,
        'octobre' => 10,
        'novembre' => 11,
        'decembre' => 12,
    ];

    /** @var list<string> */
    private const TODAY = ['today', 'hoy', "aujourd'hui"];

    /** @var list<string> */
    private const TOMORROW = ['tomorrow', 'manana', 'demain'];

    /** @var list<string> */
    private const DAY_AFTER = ['day after tomorrow', 'pasado manana', 'apres demain'];

    /** @var list<string> */
    private const START_NEXT_MONTH = [
        'start of next month',
        'beginning of next month',
        'inicio del proximo mes',
        'principio del proximo mes',
        'comienzo del proximo mes',
        'inicio del mes que viene',
        'debut du mois prochain',
        'debut du prochain mois',
    ];

    /** @var list<string> */
    private const END_OF_MONTH = [
        'end of the month',
        'end of this month',
        'end of month',
        'fin de mes',
        'fin del mes',
        'final de mes',
        'fin de este mes',
        'fin du mois',
        'fin de ce mois',
        'fin du mois en cours',
    ];

    public static function resolve(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        $folded = self::fold($phrase);
        if ($folded === '') {
            return null;
        }

        $iso = self::iso($folded);
        if ($iso !== null) {
            return $iso;
        }

        $european = self::europeanDate($folded);
        if ($european !== null) {
            return $european;
        }

        $spaced = trim(str_replace('-', ' ', $folded));
        $spaced = trim((string) preg_replace('/\s+/u', ' ', $spaced));
        $spaced = self::stripLeadingArticles($spaced);
        if ($spaced === '') {
            return null;
        }

        return self::namedDay($spaced, $today)
            ?? self::monthBoundary($spaced, $today)
            ?? self::inDuration($spaced, $today)
            ?? self::weekdayRelative($spaced, $today)
            ?? self::dayMonth($spaced, $today)
            ?? self::slashDayMonth($spaced, $today);
    }

    private static function fold(string $text): string
    {
        $normalized = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text)
            : $text;
        $stripped = preg_replace('/\p{Mn}/u', '', $normalized) ?? $normalized;
        $apostrophe = str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", $stripped);
        $trimmed = trim(mb_strtolower($apostrophe));
        $trimmed = trim($trimmed, "\"'«»“”.");

        return trim((string) preg_replace('/\s+/u', ' ', $trimmed));
    }

    private static function stripLeadingArticles(string $phrase): string
    {
        $current = $phrase;
        while (preg_match('/^(the|el|la|los|las|le|les)\s+/u', $current) === 1) {
            $current = (string) preg_replace('/^(the|el|la|los|las|le|les)\s+/u', '', $current, 1);
        }

        return $current;
    }

    private static function iso(string $folded): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $folded, $match) !== 1) {
            return null;
        }

        $year = (int) $match[1];
        $month = (int) $match[2];
        $day = (int) $match[3];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->startOfDay();
    }

    private static function europeanDate(string $folded): ?CarbonImmutable
    {
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $folded, $match) !== 1) {
            return null;
        }

        $day = (int) $match[1];
        $month = (int) $match[2];
        $year = (int) $match[3];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)?->startOfDay();
    }

    private static function namedDay(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        if (in_array($phrase, self::TODAY, true)) {
            return $today;
        }
        if (in_array($phrase, self::TOMORROW, true)) {
            return $today->addDay();
        }
        if (in_array($phrase, self::DAY_AFTER, true)) {
            return $today->addDays(2);
        }

        return null;
    }

    private static function monthBoundary(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        if (in_array($phrase, self::START_NEXT_MONTH, true)) {
            return $today->addMonthNoOverflow()->startOfMonth();
        }
        if (in_array($phrase, self::END_OF_MONTH, true)) {
            return $today->endOfMonth()->startOfDay();
        }

        return null;
    }

    private static function inDuration(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        if (preg_match(
            '/^(?:in|en|dans)\s+(\d+)\s+(days?|dias?|jours?|weeks?|semanas?|semaines?|months?|mes(?:es)?|mois)$/u',
            $phrase,
            $match,
        ) !== 1) {
            return null;
        }

        $n = (int) $match[1];
        if ($n < 1) {
            return null;
        }

        $unit = $match[2];
        if (preg_match('/^(days?|dias?|jours?)$/u', $unit) === 1) {
            return $today->addDays($n);
        }
        if (preg_match('/^(weeks?|semanas?|semaines?)$/u', $unit) === 1) {
            return $today->addWeeks($n);
        }

        return $today->addMonthsNoOverflow($n);
    }

    private static function weekdayRelative(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        $weekdays = implode('|', array_keys(self::WEEKDAYS));

        if (preg_match('/^(?:next|proximo|prochaine?)\s+('.$weekdays.')$/u', $phrase, $match) === 1
            || preg_match('/^('.$weekdays.')\s+(?:prochain|prochaine|que viene)$/u', $phrase, $match) === 1
        ) {
            $dow = self::WEEKDAYS[$match[1]];

            return $today->next($dow)->startOfDay();
        }

        if (preg_match('/^(?:this|este|esta|ce|cet|cette)\s+('.$weekdays.')$/u', $phrase, $match) === 1) {
            $dow = self::WEEKDAYS[$match[1]];
            if ((int) $today->dayOfWeek === $dow) {
                return $today;
            }

            return $today->next($dow)->startOfDay();
        }

        return null;
    }

    private static function dayMonth(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        $months = implode('|', array_keys(self::MONTHS));
        $ordinal = '(?:st|nd|rd|th)?';

        if (preg_match('/^(\d{1,2})'.$ordinal.'\s+(?:of\s+)?('.$months.')$/u', $phrase, $match) === 1) {
            return self::nextOccurrence((int) $match[1], self::MONTHS[$match[2]], $today);
        }
        if (preg_match('/^('.$months.')\s+(\d{1,2})'.$ordinal.'$/u', $phrase, $match) === 1) {
            return self::nextOccurrence((int) $match[2], self::MONTHS[$match[1]], $today);
        }
        if (preg_match('/^(\d{1,2})\s+de\s+('.$months.')$/u', $phrase, $match) === 1) {
            return self::nextOccurrence((int) $match[1], self::MONTHS[$match[2]], $today);
        }
        if (preg_match('/^le\s+(\d{1,2})\s+('.$months.')$/u', $phrase, $match) === 1) {
            return self::nextOccurrence((int) $match[1], self::MONTHS[$match[2]], $today);
        }

        return null;
    }

    private static function slashDayMonth(string $phrase, CarbonImmutable $today): ?CarbonImmutable
    {
        if (preg_match('/^(\d{1,2})[\/\s](\d{1,2})$/', $phrase, $match) !== 1) {
            return null;
        }

        return self::nextOccurrence((int) $match[1], (int) $match[2], $today);
    }

    private static function nextOccurrence(int $day, int $month, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($month < 1 || $month > 12) {
            return null;
        }

        $year = $today->year;
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $candidate = CarbonImmutable::create($year, $month, $day)?->startOfDay();
        if ($candidate === null) {
            return null;
        }
        if ($candidate->lt($today)) {
            $nextYear = $year + 1;
            if (! checkdate($month, $day, $nextYear)) {
                return null;
            }
            $candidate = CarbonImmutable::create($nextYear, $month, $day)?->startOfDay();
        }

        return $candidate;
    }
}
