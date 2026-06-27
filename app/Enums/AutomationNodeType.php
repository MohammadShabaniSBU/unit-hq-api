<?php

namespace App\Enums;

enum AutomationNodeType: string
{
    // Triggers
    case PropertyUpdate = 'property_update';
    case ObjectCreation = 'object_creation';
    case Schedule = 'schedule';

    // Actions
    case UpdateObject = 'update_object';
    case SendEmail = 'send_email';

    public function kind(): AutomationNodeKind
    {
        return match ($this) {
            self::PropertyUpdate, self::ObjectCreation, self::Schedule => AutomationNodeKind::Trigger,
            self::UpdateObject, self::SendEmail => AutomationNodeKind::Action,
        };
    }
}
