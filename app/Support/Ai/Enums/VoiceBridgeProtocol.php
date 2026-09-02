<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum VoiceBridgeProtocol: string
{
    case Http = 'http';
    case A2a = 'a2a';
}
