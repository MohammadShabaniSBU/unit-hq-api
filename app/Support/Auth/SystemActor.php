<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Marker actor for headless writes (scheduler, queues, webhooks, automations).
 * Gate::before short-circuits to allow; never use as an activity causer.
 */
final class SystemActor implements Authorizable
{
    public function can($abilities, $arguments = []): bool
    {
        return true;
    }

    public function cant($abilities, $arguments = []): bool
    {
        return false;
    }

    public function cannot($abilities, $arguments = []): bool
    {
        return false;
    }
}
