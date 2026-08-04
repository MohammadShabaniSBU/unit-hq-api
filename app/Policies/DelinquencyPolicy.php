<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Delinquency;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class DelinquencyPolicy extends BasePolicy
{
    public function view(Employee $employee, Delinquency $delinquency): bool
    {
        return $this->allows($employee, Permission::DelinquencyView, $delinquency);
    }

    public function act(Employee $employee, Delinquency $delinquency): bool
    {
        return $this->allows($employee, Permission::DelinquencyAct, $delinquency);
    }

    public function writeOff(Employee $employee, Delinquency $delinquency): bool
    {
        return $this->allows($employee, Permission::DelinquencyWriteOff, $delinquency);
    }
}
