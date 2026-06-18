<?php

namespace App\Enums;

enum ContactChannelType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
}
