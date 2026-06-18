<?php

namespace App\Enums;

enum PreferredChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Phone = 'phone';
}
