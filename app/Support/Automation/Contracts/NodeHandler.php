<?php

declare(strict_types=1);

namespace App\Support\Automation\Contracts;

use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Support\Automation\RunContext;

interface NodeHandler
{
    /**
     * @return array<string, mixed>  Step output published to RunContext under the node's node_key
     */
    public function handle(
        AutomationRun $run,
        AutomationRunStep $step,
        AutomationNode $node,
        RunContext $context,
    ): array;
}
