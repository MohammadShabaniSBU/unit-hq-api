<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\ContractEndedReason;
use App\Enums\ContractItemChangeReason;
use App\Enums\ContractStatus;
use App\Enums\TransferPricingMode;
use App\Http\Resources\ContractResource;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractTransfer;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use App\Support\Billing\TransferSettlement;
use App\Support\Contracts\ContractTransition;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Transfer / transfer-preview verb endpoints. Settlement math lives in
 * TransferSettlement so preview and commit never diverge.
 */
trait TransfersContracts
{
    public function transferPreview(Request $request, Contract $contract): JsonResponse
    {
        $validated = $this->validateTransferBody($request);
        $plan = $this->buildTransferPlan($contract, $validated);
        $plan['invoices_to_issue'] = $this->previewTransferInvoices($plan);

        return $this->success($plan, 'Transfer preview computed successfully.');
    }

    public function transfer(Request $request, Contract $contract): JsonResponse
    {
        $validated = $this->validateTransferBody($request);

        DB::transaction(function () use ($contract, $validated): void {
            $contract = Contract::query()
                ->with(['items.price', 'charges', 'unitItem.item.site'])
                ->lockForUpdate()
                ->findOrFail($contract->id);

            ContractTransition::assertTransferable($contract);

            $transferDate = CarbonImmutable::parse($validated['transfer_date'])->startOfDay();
            $mode = TransferPricingMode::from(
                $validated['pricing_mode'] ?? TransferPricingMode::DestinationRate->value
            );
            $destination = Unit::query()
                ->with(['unitClass', 'site'])
                ->findOrFail($validated['to_unit_id']);

            $originItem = $this->openUnitItem($contract);
            $originUnitId = (int) $originItem->item_id;

            if ($originUnitId === (int) $destination->id) {
                throw ValidationException::withMessages([
                    'to_unit_id' => [__('errors.contracts.transfer_same_unit')],
                ]);
            }

            $occupancyEndsOn = $this->destinationOccupancyEndsOn($contract);
            OccupancyGuard::assertVacant($destination->id, $transferDate, $occupancyEndsOn);
            HoldGuard::assertUnheld($destination->id, $transferDate, $occupancyEndsOn);

            $plan = TransferSettlement::compute(
                $contract,
                $destination,
                $transferDate,
                $mode,
                $originItem,
            );

            $billedThroughBefore = $contract->billedThrough();
            $depositBefore = (string) $contract->deposit_amount;

            $occupancy = $this->currentOccupancy($contract);
            $occupancy->forceFill([
                'ended_on' => $transferDate->toDateString(),
                'ended_reason' => ContractEndedReason::TransferredOut->value,
            ])->save();

            $originItem->forceFill([
                'effective_to' => $transferDate->toDateString(),
            ])->save();

            $newItem = ContractItem::query()->create([
                'contract_id' => $contract->id,
                'item_type' => 'unit',
                'item_id' => $destination->id,
                'price_id' => $plan['destination_item']['price_id'],
                'discount_id' => $originItem->discount_id,
                'base_rate' => $originItem->base_rate,
                'discount_ends_at' => $originItem->discount_ends_at,
                'tax_rate_id' => $plan['destination_item']['tax_rate_id'],
                'tax_rate_snapshot' => $plan['destination_item']['tax_rate_snapshot'],
                'declared_goods_value' => $originItem->declared_goods_value,
                'description' => $originItem->description,
                'effective_from' => $transferDate->toDateString(),
                'effective_to' => null,
                'supersedes_id' => $originItem->id,
                'change_reason' => ContractItemChangeReason::Transfer,
            ]);

            UnitOccupancy::query()->create([
                'unit_id' => $destination->id,
                'contract_id' => $contract->id,
                'contract_item_id' => $newItem->id,
                'started_on' => $transferDate->toDateString(),
                'ended_on' => $occupancyEndsOn?->toDateString(),
                'created_by' => auth()->id(),
            ]);

            $chargeIdsBefore = Charge::query()
                ->where('contract_id', $contract->id)
                ->pluck('id');

            $this->persistTransferPlan($contract, $plan, $newItem, $transferDate);

            $newCharges = Charge::query()
                ->where('contract_id', $contract->id)
                ->whereNotIn('id', $chargeIdsBefore)
                ->get();

            $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
            $split = InvoiceIssuer::splitSettlementCharges($contract, $newCharges);
            InvoiceIssuer::issueCreditsForContract(
                $contract,
                $split['credits'],
                InvoiceIssuer::REASON_TRANSFER_CREDIT,
                auth()->id(),
            );
            InvoiceIssuer::issue($contract, $split['debits'], null, auth()->id());

            if (bccomp((string) $plan['deposit']['new_deposit_amount'], $depositBefore, 2) !== 0) {
                $contract->forceFill([
                    'deposit_amount' => $plan['deposit']['new_deposit_amount'],
                ])->save();
            }

            ContractTransfer::query()->create([
                'contract_id' => $contract->id,
                'from_unit_id' => $originUnitId,
                'to_unit_id' => $destination->id,
                'from_contract_item_id' => $originItem->id,
                'to_contract_item_id' => $newItem->id,
                'transfer_date' => $transferDate->toDateString(),
                'pricing_mode' => $mode,
                'reason' => $validated['reason'] ?? null,
                'created_by' => auth()->id(),
            ]);

            RecordsActivity::core('contract.transferred', $contract, [
                'from_unit_id' => $originUnitId,
                'to_unit_id' => $destination->id,
                'transfer_date' => $transferDate->toDateString(),
                'pricing_mode' => $mode->value,
                'transfer_billing' => $plan['transfer_billing'],
                'credit' => $plan['credit']['gross'] ?? '0.00',
                'debit' => $plan['debit']['gross'] ?? '0.00',
                'deposit_differential' => $plan['deposit']['differential'],
                'deposit_surplus' => $plan['deposit']['surplus'],
                'deposit_amount_before' => $depositBefore,
                'deposit_amount_after' => (string) $plan['deposit']['new_deposit_amount'],
                'resulting_balance' => $plan['resulting_balance'],
                'reason' => $validated['reason'] ?? null,
            ]);

            $contract->refresh();
            if ($contract->billedThrough() !== $billedThroughBefore) {
                $contract->forceFill(['billed_through' => $billedThroughBefore])->save();
            }
        });

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Contract transferred successfully.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTransferBody(Request $request): array
    {
        return $request->validate([
            'to_unit_id' => ['required', 'integer', 'exists:units,id'],
            'transfer_date' => ['required', 'date'],
            'pricing_mode' => ['nullable', Rule::enum(TransferPricingMode::class)],
            'reason' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildTransferPlan(Contract $contract, array $validated): array
    {
        $contract->loadMissing(['items.price', 'charges', 'unitItem']);

        ContractTransition::assertTransferable($contract);

        $transferDate = CarbonImmutable::parse($validated['transfer_date'])->startOfDay();
        $mode = TransferPricingMode::from(
            $validated['pricing_mode'] ?? TransferPricingMode::DestinationRate->value
        );
        $destination = Unit::query()
            ->with(['unitClass', 'site'])
            ->findOrFail($validated['to_unit_id']);

        $originItem = $this->openUnitItem($contract);

        if ((int) $originItem->item_id === (int) $destination->id) {
            throw ValidationException::withMessages([
                'to_unit_id' => [__('errors.contracts.transfer_same_unit')],
            ]);
        }

        $occupancyEndsOn = $this->destinationOccupancyEndsOn($contract);
        OccupancyGuard::assertVacant($destination->id, $transferDate, $occupancyEndsOn);
        HoldGuard::assertUnheld($destination->id, $transferDate, $occupancyEndsOn);

        return TransferSettlement::compute(
            $contract,
            $destination,
            $transferDate,
            $mode,
            $originItem,
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function persistTransferPlan(
        Contract $contract,
        array $plan,
        ContractItem $newItem,
        CarbonImmutable $transferDate,
    ): void {
        if ($plan['credit'] !== null) {
            $line = $plan['credit'];
            Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => $line['contract_item_id'],
                'charge_type' => $line['charge_type'],
                'period_start' => $line['period_start'],
                'period_end' => $line['period_end'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $transferDate->toDateString(),
                'description' => $line['adjusts_charge_id'] !== null
                    ? $line['description'].' #'.$line['adjusts_charge_id']
                    : $line['description'],
                'reversal_of_charge_id' => $line['adjusts_charge_id'],
            ]);
        }

        if ($plan['debit'] !== null) {
            $line = $plan['debit'];
            Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => $newItem->id,
                'charge_type' => $line['charge_type'],
                'period_start' => $line['period_start'],
                'period_end' => $line['period_end'],
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $transferDate->toDateString(),
                'description' => $line['description'],
                'reversal_of_charge_id' => null,
            ]);
        }

        if ($plan['deposit']['charge'] !== null) {
            $line = $plan['deposit']['charge'];
            Charge::query()->create([
                'contract_id' => $contract->id,
                'contract_item_id' => null,
                'charge_type' => $line['charge_type'],
                'period_start' => null,
                'period_end' => null,
                'net_amount' => $line['net'],
                'tax_rate_snapshot' => $line['tax_rate_snapshot'],
                'tax_amount' => $line['tax'],
                'amount' => $line['gross'],
                'currency' => $contract->currency,
                'due_date' => $transferDate->toDateString(),
                'description' => $line['description'],
                'reversal_of_charge_id' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{kind: string, rectifies_full_number: string|null, gross_total: string, net_total: string, tax_total: string}>
     */
    private function previewTransferInvoices(array $plan): array
    {
        $creditLines = $plan['credit'] !== null ? [$plan['credit']] : [];
        $debitLines = $plan['debit'] !== null ? [$plan['debit']] : [];

        return InvoiceIssuer::previewInvoicesToIssue($creditLines, $debitLines);
    }

    private function openUnitItem(Contract $contract): ContractItem
    {
        $item = ContractItem::query()
            ->with('price')
            ->where('contract_id', $contract->id)
            ->where('item_type', 'unit')
            ->whereNull('effective_to')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.contracts.transfer_no_unit_item')],
            ]);
        }

        return $item;
    }

    private function currentOccupancy(Contract $contract): UnitOccupancy
    {
        $occupancy = UnitOccupancy::query()
            ->where('contract_id', $contract->id)
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($occupancy === null) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.contracts.no_open_occupancy')],
            ]);
        }

        return $occupancy;
    }

    private function destinationOccupancyEndsOn(Contract $contract): ?CarbonImmutable
    {
        $status = $contract->status instanceof ContractStatus
            ? $contract->status
            : ContractStatus::from((string) $contract->status);

        if ($status !== ContractStatus::NoticeGiven || $contract->scheduled_move_out_on === null) {
            return null;
        }

        return CarbonImmutable::parse($contract->scheduled_move_out_on)->startOfDay();
    }
}
