<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Carbon\CarbonInterface;
use Exception;

/**
 * Signal from WaitHandler: park the run and release the worker (not a failure).
 */
final class Parked extends Exception
{
    public function __construct(
        public readonly CarbonInterface $until,
        public readonly int $nodeId,
    ) {
        parent::__construct('automation run parked');
    }
}
