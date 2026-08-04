<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessGrant;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class AccessGrantPolicy extends BasePolicy
{
    public function manage(Employee $employee, AccessGrant $grant): bool
    {
        return $this->allows($employee, Permission::AccessManage, $grant);
    }
}
