<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\AiAgent;
use App\Models\AgentChannelBinding;
use App\Support\Ai\Enums\BindingAudience;
use App\Support\Ai\Enums\BindingMode;
use App\Support\Ai\Enums\OutsideHoursPolicy;

/**
 * Resolved live binding. The listener never reads the table directly.
 */
final readonly class ResolvedBinding
{
    public function __construct(
        public AiAgent $agent,
        public BindingMode $mode,
        public BindingAudience $audience,
        public OutsideHoursPolicy $outsideHours,
        public AgentChannelBinding $binding,
    ) {}
}
