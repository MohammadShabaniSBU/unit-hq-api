<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shared connection status for provider credential rows (communication
 * accounts, payment provider accounts). Kept generic so both surfaces —
 * and the credential helpers — use one vocabulary.
 */
enum CredentialStatus: string
{
    case Disconnected = 'disconnected';
    case Connected = 'connected';
    case Error = 'error';
}
