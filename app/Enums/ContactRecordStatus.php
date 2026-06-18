<?php

namespace App\Enums;

enum ContactRecordStatus: string
{
    case Active = 'active';
    case DoNotContact = 'do_not_contact';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Duplicate = 'duplicate';
    case Deceased = 'deceased';
    case Archived = 'archived';
}
