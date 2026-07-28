<?php

declare(strict_types=1);

namespace App\Enums;

enum CommunicationAccountScope: string
{
    case Company = 'company';
    case Site = 'site';
}
