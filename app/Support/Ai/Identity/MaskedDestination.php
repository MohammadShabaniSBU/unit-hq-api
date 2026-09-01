<?php

declare(strict_types=1);

namespace App\Support\Ai\Identity;

use App\Enums\ContactChannelType;
use App\Models\ContactChannel;
use App\Support\Ai\Tools\FactBag;

final class MaskedDestination
{
    public static function mask(ContactChannel $channel): string
    {
        return $channel->type === ContactChannelType::Email
            ? self::maskEmail($channel->value)
            : self::maskPhone($channel->value);
    }

    public static function license(FactBag $facts, string $masked, string $value): FactBag
    {
        $facts->identifier($masked);

        $tail = self::digitTail($value);
        if ($tail !== '') {
            $facts->number($tail);
            $facts->number((int) $tail);
        }

        return $facts;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '••••';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $first = substr($local, 0, 1);

        return $first.'•••@'.$domain;
    }

    public static function maskPhone(string $phone): string
    {
        $tail = self::digitTail($phone);

        return $tail === '' ? '••••' : '•••• '.$tail;
    }

    public static function digitTail(string $value, int $length = 4): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }

        return substr($digits, -$length);
    }
}
