<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceFiscalStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
