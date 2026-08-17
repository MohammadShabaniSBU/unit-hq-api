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
}
