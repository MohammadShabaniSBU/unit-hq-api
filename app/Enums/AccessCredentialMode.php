<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessCredentialMode: string
{
    case AppInvite = 'app_invite';
    case Pin = 'pin';
}
