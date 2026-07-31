<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Contract;
use App\Support\Fiscal\InvoiceIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retroactive sweep: stamp uninvoiced negative adjustment credits onto
 * rectificatives when their original invoice can be resolved. Unmatched
 * credits are reported for gestor review — never invent ordinary negatives.
 */
class SweepInvoiceCreditsCommand extends Command
{
    protected $signature = 'invoices:sweep-credits';

    protected $description = 'Issue rectificatives for uninvoiced credit adjustments with matchable originals; report unmatched';

    public function handle(): int
    {
        $credits = Charge::query()
            ->where('charge_type', ChargeType::Adjustment)
            ->whereNull('invoice_id')
            ->where('amount', '<', 0)
            ->orderBy('contract_id')
            ->orderBy('id')
            ->get();

        if ($credits->isEmpty()) {
            $this->info('No uninvoiced credit adjustments found.');

            return self::SUCCESS;
        }

        $issuedCount = 0;
        $unmatchedRows = [];

        $byContract = $credits->groupBy('contract_id');

        foreach ($byContract as $contractId => $contractCredits) {
            $contract = Contract::query()->find($contractId);
            if ($contract === null) {
                foreach ($contractCredits as $credit) {
                    $unmatchedRows[] = [
                        'charge_id' => $credit->id,
                        'contract_id' => $contractId,
                        'amount' => (string) $credit->amount,
                        'reason' => 'missing_contract',
                    ];
                }

                continue;
            }

            // Group by inferred reason so vacate/transfer labels stay accurate.
            $byReason = $contractCredits->groupBy(
                fn (Charge $c) => InvoiceIssuer::inferRectificationReason($c)
            );

            foreach ($byReason as $reason => $reasonCredits) {
                $result = DB::transaction(function () use ($contract, $reasonCredits, $reason) {
                    $contract->load([
                        'contact',
                        'unitItem.item.site.country',
                        'unitItem.item.site.legalEntity',
                    ]);

                    return InvoiceIssuer::issueCreditsForContract(
                        $contract,
                        $reasonCredits,
                        (string) $reason,
                        null,
                    );
                });

                $issuedCount += $result['issued']->count();

                foreach ($result['unmatched'] as $credit) {
                    $unmatchedRows[] = [
                        'charge_id' => $credit->id,
                        'contract_id' => $contract->id,
                        'amount' => (string) $credit->amount,
                        'description' => (string) ($credit->description ?? ''),
                        'reason' => 'no_matchable_original',
                    ];
                }
            }
        }

        $this->info("Issued {$issuedCount} rectificative invoice(s).");

        if ($unmatchedRows !== []) {
            $this->warn(count($unmatchedRows).' unmatched credit(s) — review with gestor (not invented):');
            $this->table(
                ['charge_id', 'contract_id', 'amount', 'description', 'reason'],
                array_map(fn (array $row) => [
                    $row['charge_id'],
                    $row['contract_id'],
                    $row['amount'],
                    $row['description'] ?? '',
                    $row['reason'],
                ], $unmatchedRows)
            );
        } else {
            $this->info('No unmatched credits.');
        }

        return self::SUCCESS;
    }
}
