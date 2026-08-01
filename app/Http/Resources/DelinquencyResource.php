<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ChargeType;
use App\Models\Charge;
use App\Models\Unit;
use App\Support\Billing\BillingMath;
use App\Support\Delinquency\DelinquencyPrediction;
use App\Support\Delinquency\DelinquencyState;
use App\Support\Delinquency\LateFeeAssessor;
use App\Support\Delinquency\Overlock;
use App\Support\Playbooks\PlaybookEnrolmentSummary;
use Illuminate\Http\Request;

class DelinquencyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $contract = $this->contract;
        $daysOverdue = $contract !== null ? DelinquencyState::daysOverdue($contract) : null;
        $splits = $this->overdueSplits();

        $unitNumbers = [];
        $siteId = null;
        $siteName = null;
        if ($contract !== null && $contract->relationLoaded('unitItem') && $contract->unitItem !== null) {
            $unit = $contract->unitItem->item;
            if ($unit instanceof Unit) {
                $unitNumbers[] = (string) $unit->unit_number;
                $siteId = $unit->site_id;
                $siteName = $unit->relationLoaded('site') ? $unit->site?->name : null;
            }
        }

        $executedPolicyStepIds = [];
        if ($this->relationLoaded('steps')) {
            $executedPolicyStepIds = $this->steps
                ->whereNotNull('policy_step_id')
                ->pluck('policy_step_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $policySteps = [];
        if ($this->relationLoaded('policy') && $this->policy?->relationLoaded('steps')) {
            $policySteps = $this->policy->steps->map(fn ($step) => [
                'id' => $step->id,
                'offset_days' => $step->offset_days,
                'action' => $step->action instanceof \BackedEnum
                    ? $step->action->value
                    : $step->action,
                'params' => $step->params ?? [],
                'sort' => $step->sort,
            ])->values()->all();
        }

        $overlocked = array_key_exists('overlocked', $this->additional)
            ? (bool) $this->additional['overlocked']
            : Overlock::liveHolds($this->resource)->isNotEmpty();

        $failedAutopay = (bool) ($this->additional['failed_autopay'] ?? false);
        $lastPaymentAt = $this->additional['last_payment_at'] ?? null;
        if ($lastPaymentAt === null && $contract !== null && $contract->relationLoaded('payments')) {
            $last = $contract->payments->sortByDesc('id')->first();
            $lastPaymentAt = $last?->created_at?->toIso8601String();
        }

        $payload = [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'delinquency_policy_id' => $this->delinquency_policy_id,
            'policy_name' => $this->when(
                $this->relationLoaded('policy'),
                fn () => $this->policy?->name
            ),
            'auto_release_overlock' => $this->when(
                $this->relationLoaded('policy'),
                fn () => (bool) ($this->policy?->auto_release_overlock ?? true)
            ),
            'anchor_due_date' => $this->date($this->anchor_due_date),
            'opened_on' => $this->date($this->opened_on),
            'cured_on' => $this->date($this->cured_on),
            'cure_trigger' => $this->cure_trigger instanceof \BackedEnum
                ? $this->cure_trigger->value
                : $this->cure_trigger,
            'paused_at' => $this->datetime($this->paused_at),
            'paused_reason' => $this->paused_reason,
            'is_paused' => $this->isPaused(),
            'is_open' => $this->isOpen(),
            'contact_id' => $contract?->contact_id,
            'contact_name' => $contract?->relationLoaded('contact') && $contract->contact !== null
                ? trim($contract->contact->first_name.' '.$contract->contact->last_name)
                : null,
            'site_id' => $siteId,
            'site_name' => $siteName,
            'unit_numbers' => $unitNumbers,
            'currency' => $contract?->currency,
            'days_overdue' => $daysOverdue,
            'overdue_rent' => $splits['rent'],
            'overdue_fees' => $splits['fees'],
            'overdue_total' => $splits['total'],
            'overlocked' => $overlocked,
            'autopay_enabled' => (bool) ($contract?->autopay_enabled ?? false),
            'failed_autopay' => $failedAutopay,
            'last_payment_at' => $lastPaymentAt,
            'policy_steps' => $policySteps,
            'executed_policy_step_ids' => $executedPolicyStepIds,
            'next_step' => $this->isOpen() ? DelinquencyPrediction::nextStep($this->resource) : null,
            'active_playbook_enrolment' => ($this->additional['include_playbook_enrolment'] ?? false) && $this->isOpen()
                ? PlaybookEnrolmentSummary::activeForSubject('delinquency', (int) $this->id)
                : null,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];

        if ($this->additional['include_timeline'] ?? false) {
            $payload['timeline'] = $this->when(
                $this->relationLoaded('steps'),
                fn () => DelinquencyStepResource::collection($this->steps)->resolve()
            );
            $payload['fee_suggestion'] = $this->isOpen() && $contract !== null
                ? LateFeeAssessor::suggestion($this->resource, $contract)
                : null;
            $payload['live_overlock_unit_ids'] = array_key_exists('live_overlock_unit_ids', $this->additional)
                ? $this->additional['live_overlock_unit_ids']
                : Overlock::liveHolds($this->resource)
                    ->pluck('unit_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
        }

        return $payload;
    }

    /**
     * @return array{rent: string, fees: string, total: string}
     */
    private function overdueSplits(): array
    {
        $contract = $this->contract;
        if ($contract === null) {
            return ['rent' => '0.00', 'fees' => '0.00', 'total' => '0.00'];
        }

        if ($contract->relationLoaded('charges')) {
            try {
                $today = DelinquencyState::siteToday($contract)->toDateString();
            } catch (\Throwable) {
                return ['rent' => '0.00', 'fees' => '0.00', 'total' => '0.00'];
            }

            $triggerValues = array_map(
                fn (ChargeType $t): string => $t->value,
                DelinquencyState::TRIGGER_TYPES,
            );

            $charges = $contract->charges->filter(function (Charge $charge) use ($today, $triggerValues): bool {
                $due = $charge->due_date?->toDateString() ?? (string) $charge->due_date;
                $type = $charge->charge_type instanceof ChargeType
                    ? $charge->charge_type->value
                    : (string) $charge->charge_type;

                return $due < $today
                    && in_array($type, $triggerValues, true)
                    && bccomp($charge->openAmount(), '0.00', 2) > 0;
            });
        } else {
            $charges = DelinquencyState::overdueCharges($contract);
        }

        $rent = '0.00';
        $fees = '0.00';

        foreach ($charges as $charge) {
            $open = $charge->openAmount();
            $type = $charge->charge_type instanceof ChargeType
                ? $charge->charge_type
                : ChargeType::from((string) $charge->charge_type);

            if (in_array($type, [ChargeType::Rent, ChargeType::Insurance], true)) {
                $rent = bcadd($rent, $open, 2);
            } else {
                $fees = bcadd($fees, $open, 2);
            }
        }

        return [
            'rent' => BillingMath::round2($rent),
            'fees' => BillingMath::round2($fees),
            'total' => BillingMath::round2(bcadd($rent, $fees, 2)),
        ];
    }
}
