<?php

namespace App\Enums;

enum ContactAddressType: string
{
    case Home = 'home';
    case Work = 'work';
    case Billing = 'billing';
    case Other = 'other';
}
