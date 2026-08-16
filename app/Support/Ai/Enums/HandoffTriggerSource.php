<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum HandoffTriggerSource: string
{
    case Rule = 'rule';
    case Model = 'model';
    case Customer = 'customer';
    case Guardrail = 'guardrail';
}
