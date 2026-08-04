<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Site;
use App\Support\Auth\Permission;

final class SitePolicy extends BasePolicy
{
    public function view(Employee $employee, Site $site): bool
    {
        return $this->allows($employee, Permission::UnitView, $site);
    }

    public function manage(Employee $employee, Site $site): bool
    {
        return $this->allows($employee, Permission::SiteManage, $site);
    }

    public function manageCredentials(Employee $employee, Site $site): bool
    {
        return $this->allows($employee, Permission::CredentialManage, $site);
    }
}
