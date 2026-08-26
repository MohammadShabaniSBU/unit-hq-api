<?php

declare(strict_types=1);

namespace App\Support\Ai;

use RuntimeException;

final class AgentSendRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function suppressed(?string $detail): self
    {
        return new self('suppressed', $detail ?? 'Address is suppressed.');
    }
}
