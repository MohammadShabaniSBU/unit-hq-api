<?php

declare(strict_types=1);

namespace App\Support\Ai\Enums;

enum ToolErrorCode: string
{
    case SiteUnresolved = 'site_unresolved';
    case UnlicensedArgument = 'unlicensed_argument';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
    case InvalidArguments = 'invalid_arguments';
    case Unavailable = 'unavailable';
    case PriceSuperseded = 'price_superseded';
}
