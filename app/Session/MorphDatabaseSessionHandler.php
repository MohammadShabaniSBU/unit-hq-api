<?php

namespace App\Session;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Session\DatabaseSessionHandler;

class MorphDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function addUserInformation(&$payload): static
    {
        if (! $this->container->bound(Guard::class)) {
            return $this;
        }

        $user = $this->container->make(Guard::class)->user();

        $payload['sessionable_type'] = $user instanceof Authenticatable ? $user->getMorphClass() : null;
        $payload['sessionable_id'] = $user instanceof Authenticatable ? $user->getKey() : null;

        return $this;
    }
}
