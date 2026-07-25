<?php

declare(strict_types=1);

namespace App\Enums;

enum AttributeEntityType: string
{
    case Contact = 'contact';
    case Deal = 'deal';
    case Offer = 'offer';
    case Reservation = 'reservation';
    case Unit = 'unit';
    case Contract = 'contract';

    public function activityChannel(): LogChannel
    {
        return $this === self::Unit ? LogChannel::Facility : LogChannel::Crm;
    }
}
