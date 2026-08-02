<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Document = 'document';
}
