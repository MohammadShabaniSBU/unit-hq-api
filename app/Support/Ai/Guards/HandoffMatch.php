<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Support\Ai\Enums\HandoffReason;

final readonly class HandoffMatch
{
    /**
     * @param  array<string, mixed>|null  $detail
     */
    public function __construct(
        public HandoffReason $reason,
        public string $cannedDraft,
        public ?array $detail = null,
    ) {}
}
