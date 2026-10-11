<?php

declare(strict_types=1);

namespace App\Support\Marketing;

enum SubscriptionListKind: string
{
    case Promotions = 'promotions';
    case Newsletter = 'newsletter';
    case Advertising = 'advertising';
}
