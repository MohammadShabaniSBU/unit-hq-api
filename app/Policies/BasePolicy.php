<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee;
use App\Support\Auth\DenialContext;
use App\Support\Auth\Permission;
use App\Support\Auth\SubjectSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Thin policy helpers — permission + subject site only.
 * Business-state preconditions stay in controllers/models.
 */
abstract class BasePolicy
{
    protected function allows(Employee $employee, Permission $permission, Model $subject): bool
    {
        $site = SubjectSite::for($subject);

        if ($employee->allowsPermission($permission, $site)) {
            return true;
        }

        DenialContext::set($permission, $site?->id);

        return false;
    }
}
