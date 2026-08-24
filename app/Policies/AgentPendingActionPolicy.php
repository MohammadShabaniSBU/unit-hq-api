<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentPendingAction;
use App\Models\Employee;
use App\Support\Auth\Permission;

final class AgentPendingActionPolicy extends BasePolicy
{
    public function view(Employee $employee, AgentPendingAction $action): bool
    {
        return $this->allows($employee, Permission::AgentActionApprove, $action);
    }

    public function approve(Employee $employee, AgentPendingAction $action): bool
    {
        return $this->allows($employee, Permission::AgentActionApprove, $action);
    }

    public function reject(Employee $employee, AgentPendingAction $action): bool
    {
        return $this->allows($employee, Permission::AgentActionApprove, $action);
    }
}
