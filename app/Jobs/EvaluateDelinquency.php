<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DelinquencyCureTrigger;
use App\Models\Contract;
use App\Support\Delinquency\DelinquencyEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-evaluate a contract's delinquency after money moves (or vacate / write-off).
 * Dispatched afterCommit so a failed evaluation cannot roll back a real payment.
 */
class EvaluateDelinquency implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $contractId,
        public readonly DelinquencyCureTrigger $cureTrigger = DelinquencyCureTrigger::Payment,
        public readonly bool $afterPause = false,
    ) {}

    public static function dispatchFor(
        Contract $contract,
        DelinquencyCureTrigger $cureTrigger = DelinquencyCureTrigger::Payment,
        bool $afterPause = false,
    ): void {
        self::dispatch((int) $contract->id, $cureTrigger, $afterPause);
    }

    public function handle(): void
    {
        $contract = Contract::query()->find($this->contractId);
        if ($contract === null) {
            return;
        }

        (new DelinquencyEngine)->evaluateContract(
            $contract,
            $this->cureTrigger,
            $this->afterPause,
        );
    }
}
