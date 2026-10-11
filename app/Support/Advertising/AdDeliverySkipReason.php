<?php

declare(strict_types=1);

namespace App\Support\Advertising;

enum AdDeliverySkipReason: string
{
    case NoConsent = 'no_consent';
    case NoIdentifier = 'no_identifier';
    case ClickTooOld = 'click_too_old';
    case MappingInactive = 'mapping_inactive';
    case AccountInactive = 'account_inactive';
    case EventTooOld = 'event_too_old';
}
