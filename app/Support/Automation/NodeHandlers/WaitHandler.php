<?php

declare(strict_types=1);

namespace App\Support\Automation\NodeHandlers;

use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\Contracts\NodeHandler;
use App\Support\Automation\RunContext;
use RuntimeException;

/**
 * Stub — real delayed wait needs a `waiting` run status (see 10-open-decisions.md).
 */
final class WaitHandler implements NodeHandler
{
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array {
        throw new RuntimeException('logic.wait is not implemented yet');
    }
}
