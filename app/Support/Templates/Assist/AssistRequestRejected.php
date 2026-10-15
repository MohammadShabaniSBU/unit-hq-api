<?php

declare(strict_types=1);

namespace App\Support\Templates\Assist;

use RuntimeException;

/**
 * A turn the API must refuse before a job is queued.
 */
final class AssistRequestRejected extends RuntimeException
{
    private function __construct(
        public readonly string $errorKey,
        public readonly int $status,
    ) {
        parent::__construct($errorKey);
    }

    public static function dailyLimit(): self
    {
        return new self('assist.daily_limit_reached', 429);
    }

    public static function draftChanged(): self
    {
        return new self('assist.draft_changed', 409);
    }

    public static function notFound(): self
    {
        return new self('assist.not_found', 404);
    }
}
