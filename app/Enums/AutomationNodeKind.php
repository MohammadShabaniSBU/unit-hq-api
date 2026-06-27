<?php

namespace App\Enums;

enum AutomationNodeKind: string
{
    case Trigger = 'trigger';
    case Action = 'action';
    case Condition = 'condition';
}
