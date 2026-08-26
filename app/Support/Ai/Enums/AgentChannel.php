<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum AgentChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Webchat = 'webchat';
    case Internal = 'internal';
    case Voice = 'voice';

    /**
     * Channels an operator may bind an agent to. Voice and internal are
     * rejected at validation with the same 422; a future enum case is not
     * bindable until it is listed here.
     *
     * @return array<int, self>
     */
    public static function bindable(): array
    {
        return [self::Email, self::Sms, self::Whatsapp, self::Webchat];
    }

    public function isBindable(): bool
    {
        return in_array($this, self::bindable(), true);
    }
}
