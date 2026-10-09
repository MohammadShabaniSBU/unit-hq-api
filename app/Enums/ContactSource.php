<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactSource: string
{
    case AiAgent = 'ai_agent';

    /** Public signup form. Documented exception to inbound-never-creates (S30-07 invariant 79). */
    case SignupForm = 'signup_form';
}
