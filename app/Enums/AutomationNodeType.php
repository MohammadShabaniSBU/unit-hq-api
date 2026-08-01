<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationNodeType: string
{
    // Triggers
    case ObjectCreated = 'trigger.object_created';
    case ObjectUpdated = 'trigger.object_updated';
    case Schedule = 'trigger.schedule';
    case EmailReceived = 'trigger.email_received';

    // Actions
    case UpdateObject = 'action.update_object';
    case CreateObject = 'action.create_object';
    case SendEmail = 'action.send_email';
    case SendSms = 'action.send_sms';
    case RecordNotice = 'action.record_notice';

    // Logic
    case Branch = 'logic.branch';
    case Wait = 'logic.wait';

    public function kind(): AutomationNodeKind
    {
        return match ($this) {
            self::ObjectCreated,
            self::ObjectUpdated,
            self::Schedule,
            self::EmailReceived => AutomationNodeKind::Trigger,
            self::UpdateObject,
            self::CreateObject,
            self::SendEmail,
            self::SendSms,
            self::RecordNotice => AutomationNodeKind::Action,
            self::Branch,
            self::Wait => AutomationNodeKind::Condition,
        };
    }

    public function isTrigger(): bool
    {
        return $this->kind() === AutomationNodeKind::Trigger;
    }
}
