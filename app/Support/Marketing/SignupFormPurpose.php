<?php

declare(strict_types=1);

namespace App\Support\Marketing;

enum SignupFormPurpose: string
{
    case Subscribe = 'subscribe';
    case Enquiry = 'enquiry';
}
