<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * How a role may be granted on employee_roles.site_id.
 *
 * - company: site_id must be null
 * - site: site_id required
 * - any: either
 */
enum RoleScope: string
{
    case Company = 'company';
    case Site = 'site';
    case Any = 'any';
}
