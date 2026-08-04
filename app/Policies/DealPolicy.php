<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deal;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class DealPolicy extends BasePolicy
{
    public function view(Employee $employee, Deal $deal): bool
    {
        return $this->allows($employee, Permission::DealManage, $deal);
    }

    public function manage(Employee $employee, Deal $deal): bool
    {
        return $this->allows($employee, Permission::DealManage, $deal);
    }
}
