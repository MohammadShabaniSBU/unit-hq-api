<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class AccessEventPolicy extends BasePolicy
{
    public function view(Employee $employee, AccessEvent $event): bool
    {
        return $this->allows($employee, Permission::AccessView, $event);
    }
}
