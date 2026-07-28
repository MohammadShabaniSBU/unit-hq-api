<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Employee;
use App\Models\Site;

/**
 * Authorization helper for site-scoped credential management (comms
 * accounts, sender identities, Stripe settings).
 *
 * GAP (documented per 09-conventions-and-invariants.md / 07-people-and-auth.md):
 * `Employee` has no site-assignment table yet, so there is no way to tell a
 * "site-level staff" employee apart from a "company-level" one at the data
 * layer. Until that ships, every authenticated Employee is treated as a
 * company-level role and can manage credentials for any site. This helper
 * exists so controllers already call through a single choke point — wiring
 * real per-employee site scoping later only touches this class.
 */
final class SiteAccess
{
    public static function canManageSite(?Employee $employee, Site $site): bool
    {
        if ($employee === null) {
            return false;
        }

        // TODO: once Employee<->Site assignment exists, restrict `staff` role
        // employees to their assigned site(s). `manager` (company-level) keeps
        // seeing all sites.
        return true;
    }
}
