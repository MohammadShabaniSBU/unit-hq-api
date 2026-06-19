<?php

namespace App\Enums;

enum StorageReason: string
{
    case Freelancer = 'freelancer';
    case BusinessExtraSpace = 'business_extra_space';
    case Startup = 'startup';
    case OtherBusinessNeed = 'other_business_need';
    case OtherPersonalUse = 'other_personal_use';
    case NewHome = 'new_home';
    case HouseRenovations = 'house_renovations';
    case Travelling = 'travelling';
    case Decluttering = 'decluttering';
    case CharityNonProfit = 'charity_non_profit';
    case Other = 'other';

    // Attic reasons
    case Personal = 'personal';
    case Business = 'business';
    case Student = 'student';
}
