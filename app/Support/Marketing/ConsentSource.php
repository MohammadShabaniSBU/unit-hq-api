<?php

declare(strict_types=1);

namespace App\Support\Marketing;

enum ConsentSource: string
{
    case Form = 'form';
    case DoubleOptIn = 'double_opt_in';
    case Operator = 'operator';
    case Import = 'import';
    case PreferenceCenter = 'preference_center';
    case UnsubscribeLink = 'unsubscribe_link';
    case OneClick = 'one_click';
    case StopKeyword = 'stop_keyword';
    case Complaint = 'complaint';
    case SoftOptIn = 'soft_opt_in';
    case Redaction = 'redaction';
}
