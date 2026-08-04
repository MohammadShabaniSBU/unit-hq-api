<?php

declare(strict_types=1);

namespace App\Support\Auth;

final class EmployeeInviteLink
{
    public static function forToken(string $rawToken): string
    {
        $base = rtrim((string) config('app.panel_url', 'http://localhost:3000'), '/');

        return $base.'/invite/'.$rawToken;
    }
}
