<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\ContractEndedReason;
use App\Enums\ContractStatus;
use App\Enums\DelinquencyCureTrigger;
use App\Enums\DepositSettlementOutcome;
use App\Enums\HoldType;
use App\Http\Resources\ContractResource;
use App\Jobs\EvaluateDelinquency;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Delinquency;
use App\Models\DepositSettlement;
use App\Models\DepositSettlementLine;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Models\UnitOccupancy;
use App\Support\Billing\VacateSettlement;
use App\Support\Contracts\ContractTransition;
use App\Support\Delinquency\Overlock;
use App\Support\Fiscal\InvoiceIssuer;
use App\Support\Occupancy\HoldGuard;
use App\Support\Occupancy\OccupancyGuard;
use App\Support\RecordsActivity;
use App\Support\Time\SiteClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Notice / vacate / vacate-preview verb endpoints. Settlement math lives in
 * VacateSettlement so preview and commit never diverge.
 */
trait VacatesContracts
{
    public function notice(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_move_out_on' => ['required', 'date'],
        ]);

        $scheduled = CarbonImmutable::parse($validated['scheduled_move_out_on'])->startOfDay();

        DB::transaction(function () use ($contract, $scheduled): void {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            ContractTransition::assert($contract, ContractStatus::NoticeGiven);

            $from = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);

            $unit = $this->contractUnit($contract);
            $today = SiteClock::today($unit->site);

            $contract->forceFill([
                'status' => ContractStatus::NoticeGiven,
                'notice_given_on' => $today->toDateString(),
                'scheduled_move_out_on' => $scheduled->toDateString(),
            ])->save();

            $occupancy = $this->openOccupancy($contract);
            $occupancy->forceFill([
                'ended_on' => $scheduled->toDateString(),
            ])->save();

            RecordsActivity::core('contract.status_changed', $contract, [
                'from' => $from->value,
                'to' => ContractStatus::NoticeGiven->value,
            ]);
            RecordsActivity::core('contract.notice_given', $contract, [
                'notice_given_on' => $today->toDateString(),
                'scheduled_move_out_on' => $scheduled->toDateString(),
            ]);
        });

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Notice recorded successfully.'
        );
    }

    public function noticeWithdraw(Contract $contract): JsonResponse
    {
        DB::transaction(function () use ($contract): void {
            $contract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            ContractTransition::assert($contract, ContractStatus::Active);

            $this->assertNoticeWithdrawUnblocked($contract);

            ContractTransition::apply($contract, ContractStatus::Active);

            RecordsActivity::core('contract.notice_withdrawn', $contract, []);
        });

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Notice withdrawn successfully.'
        );
    }

    public function vacatePreview(Request $request, Contract $contract): JsonResponse
    {
        $validated = $this->validateVacateBody($request);
        $plan = $this->buildVacatePlan($contract, $validated);
        $plan['invoices_to_issue'] = $this->previewVacateInvoices($plan);

        return $this->success($plan, 'Vacate preview computed successfully.');
    }

    public function vacate(Request $request, Contract $contract): JsonResponse
    {
        $validated = $this->validateVacateBody($request);

        DB::transaction(function () use ($contract, $validated): void {
            $contract = Contract::query()
                ->with(['items.price', 'charges', 'unitItem.item.site'])
                ->lockForUpdate()
                ->findOrFail($contract->id);

            $from = $contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status);

            $moveOutOn = CarbonImmutable::parse($validated['move_out_on'])->startOfDay();
            $outcome = DepositSettlementOutcome::from($validated['deposit']['outcome']);
            $deductions = $validated['deposit']['deductions'] ?? [];

            $noticeGivenOn = $contract->notice_given_on !== null
                ? CarbonImmutable::parse($contract->notice_given_on)->startOfDay()
                : $moveOutOn;

            if ($from === ContractStatus::Active) {
                $unit = $this->contractUnit($contract);
                $today = SiteClock::today($unit->site);
                $contract->forceFill([
                    'notice_given_on' => $today->toDateString(),
                    'scheduled_move_out_on' => $moveOutOn->toDateString(),
                ]);
                $noticeGivenOn = $today;
            }

            $plan = VacateSettlement::compute(
                $contract,
                $moveOutOn,
                $outcome,
                $deductions,
                $noticeGivenOn,
            );

            ContractTransition::assert($contract, ContractStatus::Ended);

            $this->assertOverlockAllowsVacate($contract);

            $billedThroughBefore = $contract->billedThrough();

            $contract->forceFill([
                'status' => ContractStatus::Ended,
                'move_out_on' => $moveOutOn->toDateString(),
                'ended_reason' => ContractEndedReason::Vacated,
                'notice_given_on' => $noticeGivenOn->toDateString(),
                'scheduled_move_out_on' => $contract->scheduled_move_out_on
                    ?? $moveOutOn->toDateString(),
            ])->save();

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

            $occupancy->forceFill([
                'ended_on' => $moveOutOn->toDateString(),
                'ended_reason' => ContractEndedReason::Vacated->value,
            ])->save();

            ContractItem::query()
                ->where('contract_id', $contract->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $moveOutOn->toDateString()]);

            $chargeIdsBefore = Charge::query()
                ->where('contract_id', $contract->id)
                ->pluck('id');

            $this->persistVacatePlan($contract, $plan, $moveOutOn);

            $newCharges = Charge::query()
                ->where('contract_id', $contract->id)
                ->whereNotIn('id', $chargeIdsBefore)
                ->get();

            $contract->load(['contact', 'unitItem.item.site.country', 'unitItem.item.site.legalEntity']);
            $split = InvoiceIssuer::splitSettlementCharges($contract, $newCharges);
            InvoiceIssuer::issueCreditsForContract(
                $contract,
                $split['credits'],
                InvoiceIssuer::REASON_VACATE_SETTLEMENT,
                auth()->id(),
            );
            InvoiceIssuer::issue($contract, $split['debits'], null, auth()->id());

            $this->maybeCreateTurnoverHold($contract, $moveOutOn);

            RecordsActivity::core('contract.status_changed', $contract, [
                'from' => $from->value,
                'to' => ContractStatus::Ended->value,
            ]);
            RecordsActivity::core('contract.ended', $contract, [
                'move_out_on' => $moveOutOn->toDateString(),
                'final_billing_date' => $plan['final_billing_date'],
                'ended_reason' => ContractEndedReason::Vacated->value,
                'deposit_outcome' => $plan['deposit']['outcome'],
                'payout_amount' => $plan['payout_amount'],
                'resulting_balance' => $plan['resulting_balance'],
                'billed_through' => $billedThroughBefore,
            ]);

            // Cursor must remain where it was — re-assert after writes.
            $contract->refresh();
            if ($contract->billedThrough() !== $billedThroughBefore) {
                $contract->forceFill(['billed_through' => $billedThroughBefore])->save();
            }

            $vacatedContractId = (int) $contract->id;
            DB::afterCommit(static function () use ($vacatedContractId): void {
                EvaluateDelinquency::dispatch(
                    $vacatedContractId,
                    DelinquencyCureTrigger::Vacated,
                );
            });
        });

        $contract->refresh();
        $this->loadDetailRelations($contract);

        return $this->success(
            ContractResource::make($contract),
            'Contract vacated successfully.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateVacateBody(Request $request): array
    {
        return $request->validate([
            'move_out_on' => ['required', 'date'],
            'deposit' => ['required', 'array'],
            'deposit.outcome' => ['required', Rule::enum(DepositSettlementOutcome::class)],
            'deposit.deductions' => ['nullable', 'array'],
            'deposit.deductions.*.amount' => ['required_with:deposit.deductions', 'numeric', 'gt:0'],
            'deposit.deductions.*.reason' => ['required_with:deposit.deductions', 'string'],
            'deposit.deductions.*.tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildVacatePlan(Contract $contract, array $validated): array
    {
        $contract->loadMissing(['items.price', 'charges']);

        $moveOutOn = CarbonImmutable::parse($validated['move_out_on'])->startOfDay();
        $outcome = DepositSettlementOutcome::from($validated['deposit']['outcome']);
        $deductions = $validated['deposit']['deductions'] ?? [];

        $noticeGivenOn = $contract->notice_given_on !== null
            ? CarbonImmutable::parse($contract->notice_given_on)->startOfDay()
            : $moveOutOn;

        if (
            ($contract->status instanceof ContractStatus
                ? $contract->status
                : ContractStatus::from((string) $contract->status)) === ContractStatus::Active
        ) {
            $unit = $this->contractUnit($contract);
            $noticeGivenOn = SiteClock::today($unit->site);
        }

        return VacateSettlement::compute(
            $contract,
            $moveOutOn,
            $outcome,
            $deductions,
            $noticeGivenOn,
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function persistVacatePlan(Contract $contract, array $plan, CarbonImmutable $moveOutOn): void
    {
        foreach ($plan['item_lines'] as $line) {
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
                'due_date' => $moveOutOn->toDateString(),
                'description' => $line['adjusts_charge_id'] !== null
                    ? $line['description'].' #'.$line['adjusts_charge_id']
                    : $line['description'],
                'reversal_of_charge_id' => $line['adjusts_charge_id'],
            ]);
        }

        $depositPlan = $plan['deposit'];
        if (bccomp((string) $depositPlan['deposit_amount'], '0', 2) === 0) {
            return;
        }

        $settlement = DepositSettlement::query()->create([
            'contract_id' => $contract->id,
            'outcome' => $depositPlan['outcome'],
            'deposit_amount' => $depositPlan['deposit_amount'],
            'refunded_amount' => $depositPlan['refunded_amount'],
            'currency' => $contract->currency,
            'payout_status' => $depositPlan['payout_status'],
            'paid_at' => null,
            'created_by' => auth()->id(),
        ]);

        foreach ($depositPlan['lines'] as $line) {
            $charge = Charge::query()->create([
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
                'due_date' => $moveOutOn->toDateString(),
                'description' => $line['description'].': '.$line['reason'],
            ]);

            if ($line['kind'] === 'deduction' || $line['kind'] === 'refund') {
                $amount = (string) $line['gross'];
                if (bccomp($amount, '0', 2) < 0) {
                    $amount = bcmul($amount, '-1', 2);
                }

                DepositSettlementLine::query()->create([
                    'deposit_settlement_id' => $settlement->id,
                    'charge_id' => $charge->id,
                    'amount' => $amount,
                    'currency' => $contract->currency,
                    'reason' => $line['reason'],
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function maybeCreateTurnoverHold(Contract $contract, CarbonImmutable $moveOutOn): void
    {
        $days = Setting::billing()->turnoverHoldDays;
        if ($days <= 0) {
            return;
        }

        $unit = $this->contractUnit($contract);
        $endsOn = $moveOutOn->addDays($days);

        OccupancyGuard::assertVacant($unit->id, $moveOutOn, $endsOn);
        HoldGuard::assertUnheld($unit->id, $moveOutOn, $endsOn);

        UnitHold::query()->create([
            'unit_id' => $unit->id,
            'hold_type' => HoldType::Maintenance,
            'reservation_id' => null,
            'starts_on' => $moveOutOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'released_at' => null,
            'reason' => 'post_move_out_turnover',
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Never close a tenancy while an operator overlock is still live under
     * auto_release_overlock=false. When the flag is true, release before occupancy ends.
     */
    private function assertOverlockAllowsVacate(Contract $contract): void
    {
        $open = Delinquency::query()
            ->where('contract_id', $contract->id)
            ->open()
            ->with('policy')
            ->first();

        if ($open === null) {
            return;
        }

        $live = Overlock::liveHolds($open);
        if ($live->isEmpty()) {
            return;
        }

        $autoRelease = $open->policy?->auto_release_overlock ?? true;
        if (! $autoRelease) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.contracts.overlock_pending_release')],
            ]);
        }

        Overlock::release($open, 'cure');
    }

    private function assertNoticeWithdrawUnblocked(Contract $contract): void
    {
        $occupancy = UnitOccupancy::query()
            ->where('contract_id', $contract->id)
            ->whereNotNull('ended_on')
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->first();

        if ($occupancy === null || $occupancy->ended_on === null) {
            return;
        }

        $endedOn = CarbonImmutable::parse($occupancy->ended_on)->startOfDay()->toDateString();

        $conflict = UnitHold::query()
            ->where('unit_id', $occupancy->unit_id)
            ->whereNull('released_at')
            ->whereNotNull('reservation_id')
            ->where(function ($q) use ($endedOn): void {
                $q->whereNull('ends_on')->orWhere('ends_on', '>', $endedOn);
            })
            ->where(function ($q) use ($endedOn): void {
                // Overlaps [ended_on, ∞): hold.starts < ∞ and ended_on < hold.ends (or null)
                $q->where('starts_on', '>=', $endedOn)
                    ->orWhere(function ($inner) use ($endedOn): void {
                        $inner->where('starts_on', '<', $endedOn)
                            ->where(function ($e) use ($endedOn): void {
                                $e->whereNull('ends_on')->orWhere('ends_on', '>', $endedOn);
                            });
                    });
            })
            ->with('reservation')
            ->first();

        if ($conflict !== null) {
            $unit = Unit::query()->find($occupancy->unit_id);
            throw ValidationException::withMessages([
                'status' => [__('errors.contracts.notice_withdraw_conflict', [
                    'reservation_id' => $conflict->reservation_id,
                    'unit' => $unit?->unit_number ?? (string) $occupancy->unit_id,
                    'starts_on' => $conflict->starts_on instanceof Carbon
                        ? $conflict->starts_on->toDateString()
                        : (string) $conflict->starts_on,
                ])],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{kind: string, rectifies_full_number: string|null, gross_total: string, net_total: string, tax_total: string}>
     */
    private function previewVacateInvoices(array $plan): array
    {
        $creditLines = [];
        $debitLines = [];
        foreach ($plan['item_lines'] as $line) {
            if (bccomp((string) $line['gross'], '0', 2) < 0) {
                $creditLines[] = $line;
            } elseif (bccomp((string) $line['gross'], '0', 2) > 0) {
                $debitLines[] = $line;
            }
        }

        return InvoiceIssuer::previewInvoicesToIssue($creditLines, $debitLines);
    }

    private function openOccupancy(Contract $contract): UnitOccupancy
    {
        $occupancy = UnitOccupancy::query()
            ->where('contract_id', $contract->id)
            ->whereNull('ended_on')
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($occupancy === null) {
            // Notice after a prior notice? Prefer the latest open-ended or end-dated row.
            $occupancy = UnitOccupancy::query()
                ->where('contract_id', $contract->id)
                ->orderByDesc('started_on')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
        }

        if ($occupancy === null) {
            throw ValidationException::withMessages([
                'contract' => [__('errors.contracts.no_open_occupancy')],
            ]);
        }

        return $occupancy;
    }

    private function contractUnit(Contract $contract): Unit
    {
        $contract->loadMissing(['unitItem.item.site']);

        $unit = $contract->unitItem?->item;
        if (! $unit instanceof Unit) {
            // After vacate, unit item is closed — fall back to any unit item.
            $item = ContractItem::query()
                ->where('contract_id', $contract->id)
                ->where('item_type', 'unit')
                ->orderByDesc('id')
                ->first();
            $unit = $item?->item;
        }

        if (! $unit instanceof Unit) {
            throw ValidationException::withMessages([
                'contract' => ['Contract has no unit item.'],
            ]);
        }

        $unit->loadMissing('site');

        return $unit;
    }
}
