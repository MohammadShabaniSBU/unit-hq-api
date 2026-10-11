<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Support\Communications\Channel;
use InvalidArgumentException;

/**
 * Consent ledger channel. `ads` is not a comms Channel: nothing is sent on it.
 */
enum ConsentChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Ads = 'ads';

    public static function fromComms(Channel $channel): self
    {
        return match ($channel) {
            Channel::Email => self::Email,
            Channel::Sms => self::Sms,
            Channel::Whatsapp => self::Whatsapp,
            Channel::Call => throw new InvalidArgumentException('call is not a consent channel'),
        };
    }
}
