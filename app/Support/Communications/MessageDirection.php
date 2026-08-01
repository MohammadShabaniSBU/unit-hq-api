<?php

declare(strict_types=1);

namespace App\Support\Communications;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
