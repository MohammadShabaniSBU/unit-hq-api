<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationCancelCause: string
{
    case Manual = 'manual';
    case Guard = 'guard';
    case Superseded = 'superseded';
    case TriggerObjectDeleted = 'trigger_object_deleted';
}
