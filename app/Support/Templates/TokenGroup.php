<?php

declare(strict_types=1);

namespace App\Support\Templates;

enum TokenGroup: string
{
    case Contact = 'contact';
    case Contract = 'contract';
    case Deal = 'deal';
    case Links = 'links';
    case Marketing = 'marketing';
    case Newsletter = 'newsletter';
}
