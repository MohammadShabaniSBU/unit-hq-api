<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentConversation;
use App\Models\Employee;
use App\Support\Auth\DenialContext;
use App\Support\Auth\Permission;
use App\Support\Auth\SubjectSite;

final class AgentConversationPolicy extends BasePolicy
{
    public function view(Employee $employee, AgentConversation $conversation): bool
    {
        return $this->canAccess($employee, $conversation);
    }

    public function update(Employee $employee, AgentConversation $conversation): bool
    {
        return $this->canAccess($employee, $conversation);
    }

    public function create(Employee $employee): bool
    {
        return $employee->allowsPermission(Permission::AiAgentUse);
    }

    private function canAccess(Employee $employee, AgentConversation $conversation): bool
    {
        if (! $employee->allowsPermission(Permission::AiAgentUse)) {
            DenialContext::set(Permission::AiAgentUse, SubjectSite::for($conversation)?->id);

            return false;
        }

        if ((int) $conversation->created_by_employee_id === (int) $employee->id) {
            return true;
        }

        if ($employee->siteIdsFor(Permission::AiAgentUse) === null) {
            return true;
        }

        DenialContext::set(Permission::AiAgentUse, SubjectSite::for($conversation)?->id);

        return false;
    }
}
