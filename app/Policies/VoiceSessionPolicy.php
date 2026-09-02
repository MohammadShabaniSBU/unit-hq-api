<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Models\VoiceSession;
use App\Support\Auth\Permission;

final class VoiceSessionPolicy extends BasePolicy
{
    public function view(Employee $employee, VoiceSession $session): bool
    {
        return $this->allows($employee, Permission::AiAgentUse, $session);
    }
}
