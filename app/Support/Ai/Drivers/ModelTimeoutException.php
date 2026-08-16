<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use RuntimeException;

final class ModelTimeoutException extends RuntimeException
{
    public function __construct(string $message = 'Agent model call timed out.')
    {
        parent::__construct($message);
    }
}
