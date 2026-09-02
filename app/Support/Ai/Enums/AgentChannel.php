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
     * Channels an operator may bind an agent to. Internal is rejected at
     * validation; a future enum case is not bindable until it is listed here.
     *
     * @return array<int, self>
     */
    public static function bindable(): array
    {
        return [self::Email, self::Sms, self::Whatsapp, self::Webchat, self::Voice];
    }

    public function isBindable(): bool
    {
        return in_array($this, self::bindable(), true);
    }
}
