<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Employee;
use App\Support\Automation\AutomationContext;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the authorization actor for the current execution context.
 * Automation-originated writes are system writes (same context as loop suppression).
 */
final class Actor
{
    public static function current(): Authorizable
    {
        if (AutomationContext::active()) {
            return new SystemActor;
        }

        $user = Auth::user();
        if ($user instanceof Employee) {
            return $user;
        }

        return new SystemActor;
    }
}
