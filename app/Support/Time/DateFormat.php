<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Models\Setting;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Org display-date formatting. Storage and API JSON stay ISO (`Y-m-d` / datetime).
 * This helper is for human-facing strings only (invoices, PDFs, emails).
 */
final class DateFormat
{
    public const DMY_SLASH = 'd/m/y';

    public const MDY_SLASH = 'm/d/y';

    public const DMY_DASH = 'd-m-y';

    public const DEFAULT = self::DMY_SLASH;

    /** @return list<string> */
    public static function values(): array
    {
        return [self::DMY_SLASH, self::MDY_SLASH, self::DMY_DASH];
    }

    public static function normalize(?string $format): string
    {
        $value = is_string($format) ? strtolower(trim($format)) : '';

        return in_array($value, self::values(), true) ? $value : self::DEFAULT;
    }

    public static function phpPattern(?string $format = null): string
    {
        return match (self::normalize($format)) {
            self::MDY_SLASH => 'm/d/Y',
            self::DMY_DASH => 'd-m-Y',
            default => 'd/m/Y',
        };
    }

    public static function current(): string
    {
        return self::normalize(Setting::general()->dateFormat);
    }

    public static function display(mixed $date, ?string $format = null): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $pattern = self::phpPattern($format ?? self::current());

        if ($date instanceof DateTimeInterface) {
            return $date->format($pattern);
        }

        return Carbon::parse((string) $date)->format($pattern);
    }

    public static function displayDateTime(mixed $date, ?string $format = null): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $pattern = self::phpPattern($format ?? self::current()).' H:i';

        if ($date instanceof DateTimeInterface) {
            return $date->format($pattern);
        }

        return Carbon::parse((string) $date)->format($pattern);
    }

    /**
     * @param  CarbonInterface|string|null  $start
     * @param  CarbonInterface|string|null  $end
     */
    public static function displayPeriod(mixed $start, mixed $end, ?string $format = null): ?string
    {
        $from = $start !== null && $start !== '' ? self::display($start, $format) : '';
        $to = $end !== null && $end !== '' ? self::display($end, $format) : '';

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '' || $to === '') {
            return $from !== '' ? $from : $to;
        }

        return "{$from} – {$to}";
    }
}
