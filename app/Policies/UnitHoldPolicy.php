<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\UnitHold;
use App\Support\Auth\Permission;

final class UnitHoldPolicy extends BasePolicy
{
    public function manage(Employee $employee, UnitHold $hold): bool
    {
        return $this->allows($employee, Permission::UnitHoldManage, $hold);
    }
}
