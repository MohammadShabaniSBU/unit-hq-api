<?php

declare(strict_types=1);

namespace App\Support\Ai;

use RuntimeException;

/**
 * A `bridge_session_id` already belongs to a different VoiceBridgeToken.
 * Callers must not reuse or append to that session.
 */
final class VoiceSessionCrossTokenException extends RuntimeException
{
    public function __construct(string $message = 'Voice session belongs to a different bridge token.')
    {
        parent::__construct($message);
    }
}
