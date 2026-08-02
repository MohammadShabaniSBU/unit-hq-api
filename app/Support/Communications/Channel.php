<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Call = 'call';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Sms => 'SMS',
            self::Whatsapp => 'WhatsApp',
            self::Call => 'Calls',
        };
    }

    /**
     * Channels with a live path. Call is receive-only (Aircall).
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::Email, self::Sms, self::Whatsapp, self::Call => true,
        };
    }

    /** @return list<self> */
    public static function implemented(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $channel): bool => $channel->isImplemented()
        ));
    }
}
