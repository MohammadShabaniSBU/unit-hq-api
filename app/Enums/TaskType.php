<?php

namespace App\Enums;

enum TaskType: string
{
    case Call = 'call';
    case Email = 'email';
    case FollowUp = 'follow_up';
    case UnitTour = 'unit_tour';
    case Other = 'other';
}
