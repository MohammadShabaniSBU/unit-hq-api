<?php

namespace App\Enums;

enum SettingKey: string
{
    case General = 'general';
    case Billing = 'billing';
    case Leasing = 'leasing';
    case ActivityLog = 'activity_log';
}
