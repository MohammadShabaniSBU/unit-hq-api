<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Active     = 'active';
    case MovedOut   = 'moved_out';
    case Terminated = 'terminated';
    case Expired    = 'expired';
}
