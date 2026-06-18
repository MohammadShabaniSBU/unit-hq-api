<?php

namespace App\Enums;

enum LeaseStatus: string
{
    case Active = 'active';
    case MovedOut = 'moved_out';
    case Terminated = 'terminated';
    case Expired = 'expired';
}
