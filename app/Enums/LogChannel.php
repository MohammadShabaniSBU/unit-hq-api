<?php

declare(strict_types=1);

namespace App\Enums;

enum LogChannel: string
{
    case Core = 'core';
    case Crm = 'crm';
    case Facility = 'facility';
    case Comms = 'comms';
    case Billing = 'billing';

    public function tier(): int
    {
        return $this === self::Core ? 3 : 2;
    }

    public function isAlwaysOn(): bool
    {
        return $this === self::Core;
    }

    /** i18n key for panel labels — never hardcoded English. */
    public function label(): string
    {
        return 'activity.channels.'.$this->value;
    }

    /** @return array<int, self> */
    public static function optional(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $channel): bool => ! $channel->isAlwaysOn(),
        ));
    }

    /** @return array<int, string> */
    public static function optionalValues(): array
    {
        return array_map(fn (self $c): string => $c->value, self::optional());
    }
}
