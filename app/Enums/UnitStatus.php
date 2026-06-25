<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Free = 'free';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Archived = 'archived';
}
