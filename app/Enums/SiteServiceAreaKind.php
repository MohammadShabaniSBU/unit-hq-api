<?php

declare(strict_types=1);

namespace App\Enums;

enum SiteServiceAreaKind: string
{
    case Postcode = 'postcode';
    case PostcodePrefix = 'postcode_prefix';
    case AdminRegion = 'admin_region';
}
