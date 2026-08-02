<?php

declare(strict_types=1);

namespace App\Support\Contracts;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Site;
use App\Models\SystemEvent;
use App\Models\Unit;
use App\Support\Access\AccessSync;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Flips pending contracts to active when site-today reaches move_in_date.
 * Per-contract failure isolation — one bad row must not abort the batch.
 */
final class ActivatePendingContracts
{
    /**
     * @param  (Closure(int): void)|null  $beforeActivate  test seam: after eligibility, before transition
     */
    public function __construct(
        private readonly ?Closure $beforeActivate = null,
    ) {}

    /**
     * @return array{activated: int, skipped: int, failed: int}
     */
    public function run(): array
    {
        $ids = Contract::query()
            ->where('status', ContractStatus::Pending)
            ->whereNotNull('move_in_date')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $activated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $outcome = $this->processOne($id);
            } catch (Throwable $e) {
                $failed++;
                SystemEvent::record('contract.activation.failed', Contract::query()->find($id), [
                    'error_message' => $e->getMessage(),
                ]);

                continue;
            }

            match ($outcome) {
                'activated' => $activated++,
                'skipped' => $skipped++,
                'failed' => $failed++,
            };
        }

        return compact('activated', 'skipped', 'failed');
    }

    /**
     * @return 'activated'|'skipped'|'failed'
     */
    private function processOne(int $contractId): string
    {
        if ($this->beforeActivate !== null) {
            ($this->beforeActivate)($contractId);
        }

        return DB::transaction(function () use ($contractId): string {
            /** @var Contract|null $contract */
            $contract = Contract::query()
                ->with(['unitItem.item.site'])
                ->lockForUpdate()
                ->find($contractId);

            if ($contract === null) {
                return 'skipped';
            }

            if ($contract->status !== ContractStatus::Pending) {
                return 'skipped';
            }

            $site = $this->resolveSite($contract);
            if ($site === null || $contract->move_in_date === null) {
                return 'skipped';
            }

            $moveIn = $contract->move_in_date->toDateString();
            if (SiteClock::today($site)->toDateString() < $moveIn) {
                return 'skipped';
            }

            ContractTransition::assert($contract, ContractStatus::Active);

            $contract->status = ContractStatus::Active;
            $contract->save();

            RecordsActivity::core('contract.activated', $contract, [
                'from' => ContractStatus::Pending->value,
                'to' => ContractStatus::Active->value,
            ]);

            AccessSync::nudge((int) $contract->id);

            return 'activated';
        });
    }

    private function resolveSite(Contract $contract): ?Site
    {
        $item = $contract->unitItem?->item;

        return $item instanceof Unit ? $item->site : null;
    }
}
