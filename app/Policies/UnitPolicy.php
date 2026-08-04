<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\Unit;
use App\Support\Auth\Permission;

final class UnitPolicy extends BasePolicy
{
    public function view(Employee $employee, Unit $unit): bool
    {
        return $this->allows($employee, Permission::UnitView, $unit);
    }

    public function manage(Employee $employee, Unit $unit): bool
    {
        return $this->allows($employee, Permission::UnitManage, $unit);
    }

    public function manageHold(Employee $employee, Unit $unit): bool
    {
        return $this->allows($employee, Permission::UnitHoldManage, $unit);
    }

    /** Contract create authorizes ContractSign against the unit (site carrier). */
    public function signContract(Employee $employee, Unit $unit): bool
    {
        return $this->allows($employee, Permission::ContractSign, $unit);
    }
}
