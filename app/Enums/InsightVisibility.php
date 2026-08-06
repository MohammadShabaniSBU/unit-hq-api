<?php

declare(strict_types=1);

namespace App\Enums;

enum InsightVisibility: string
{
    case All = 'all';
    case CompanyOnly = 'company_only';
    case SiteStaff = 'site_staff';
}
