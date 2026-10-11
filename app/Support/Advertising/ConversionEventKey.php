<?php

declare(strict_types=1);

namespace App\Support\Advertising;

enum ConversionEventKey: string
{
    case Lead = 'lead';
    case Reservation = 'reservation';
    case MoveIn = 'move_in';
}
