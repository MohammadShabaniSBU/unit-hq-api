<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class AccessPointPolicy extends BasePolicy
{
    public function view(Employee $employee, AccessPoint $point): bool
    {
        return $this->allows($employee, Permission::AccessView, $point);
    }

    public function manage(Employee $employee, AccessPoint $point): bool
    {
        return $this->allows($employee, Permission::AccessManage, $point);
    }
}
