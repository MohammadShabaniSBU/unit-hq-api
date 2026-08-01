<?php

declare(strict_types=1);

namespace App\Support\Delinquency;

use App\Enums\ChargeType;
use App\Enums\DelinquencyPolicyAction;
use App\Enums\DelinquencyStepAction;
use App\Enums\DelinquencyStepTrigger;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\Delinquency;
use App\Models\DelinquencyPolicyStep;
use App\Models\DelinquencyStep;
use App\Models\Employee;
use App\Support\Billing\BillingMath;

/**
 * Shared late-fee math + charge creation for ladder and manual assessment.
 */
final class LateFeeAssessor
{
    /**
     * Prefill amount from the first assess_late_fee policy step (cap-aware).
     *
     * @return array{amount: string, base: string, params: array<string, mixed>}
     */
    public static function suggestion(Delinquency $case, Contract $contract): array
    {
        $params = self::firstFeeParams($case) ?? ['type' => 'flat', 'amount' => '0.00'];
        $computed = self::compute($case, $contract, $params);

        return [
            'amount' => $computed['fee_net'],
            'base' => $computed['base'],
            'params' => $params,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{base: string, fee_net: string, skipped_zero: bool}
     */
    public static function compute(Delinquency $case, Contract $contract, array $params): array
    {
        $base = DelinquencyState::overdueBase($contract);
        $type = (string) ($params['type'] ?? 'flat');

        if ($type === 'percent') {
            $percent = (string) ($params['percent'] ?? '0');
            $raw = bcdiv(bcmul($base, $percent, 8), '100', 8);
            $feeNet = BillingMath::round2($raw);
        } else {
            $feeNet = BillingMath::round2((string) ($params['amount'] ?? '0'));
        }

        if (isset($params['cap_per_case'])) {
            $cap = BillingMath::round2((string) $params['cap_per_case']);
            $prior = self::priorFeeNets($case);
            $remaining = bcsub($cap, $prior, 2);
            if (bccomp($remaining, '0', 2) <= 0) {
                $feeNet = '0.00';
            } elseif (bccomp($feeNet, $remaining, 2) > 0) {
                $feeNet = BillingMath::round2($remaining);
            }
        }

        return [
            'base' => $base,
            'fee_net' => $feeNet,
            'skipped_zero' => bccomp($feeNet, '0', 2) <= 0,
        ];
    }

    /**
     * Create a late_fee charge from an explicit net amount (manual path).
     *
     * @return array{charge: Charge|null, step: DelinquencyStep, detail: array<string, mixed>}
     */
    public static function assessManual(
        Delinquency $case,
        Contract $contract,
        string $amount,
        string $reason,
        Employee $by,
    ): array {
        $feeNet = BillingMath::round2($amount);
        $base = DelinquencyState::overdueBase($contract);
        $today = DelinquencyState::siteToday($contract)->toDateString();

        if (bccomp($feeNet, '0', 2) <= 0) {
            $step = DelinquencyLifecycle::recordStep(
                delinquency: $case,
                action: DelinquencyStepAction::AssessLateFee,
                trigger: DelinquencyStepTrigger::Manual,
                executedOn: $today,
                detail: [
                    'skipped_zero' => true,
                    'base' => $base,
                    'fee_net' => '0.00',
                    'reason' => $reason,
                ],
                createdBy: $by,
            );

            return ['charge' => null, 'step' => $step, 'detail' => $step->detail ?? []];
        }

        $charge = self::createCharge($contract, $feeNet, $today);
        $detail = [
            'base' => $base,
            'fee_net' => $charge->net_amount,
            'fee_tax' => $charge->tax_amount,
            'fee_gross' => $charge->amount,
            'reason' => $reason,
        ];

        $step = DelinquencyLifecycle::recordStep(
            delinquency: $case,
            action: DelinquencyStepAction::AssessLateFee,
            trigger: DelinquencyStepTrigger::Manual,
            executedOn: $today,
            charge: $charge,
            detail: $detail,
            createdBy: $by,
        );

        return ['charge' => $charge, 'step' => $step, 'detail' => $detail];
    }

    /**
     * Ladder path: finish an insert-first step with fee charge (or skip-zero).
     *
     * @param  array<string, mixed>  $params
     * @return array{charge: Charge|null, detail: array<string, mixed>}
     */
    public static function applyToStep(
        DelinquencyStep $step,
        Delinquency $case,
        Contract $contract,
        array $params,
        string $executedOn,
    ): array {
        $computed = self::compute($case, $contract, $params);

        if ($computed['skipped_zero']) {
            return [
                'charge' => null,
                'detail' => [
                    'incomplete' => false,
                    'skipped_zero' => true,
                    'base' => $computed['base'],
                    'fee_net' => '0.00',
                ],
            ];
        }

        $charge = self::createCharge($contract, $computed['fee_net'], $executedOn);

        return [
            'charge' => $charge,
            'detail' => [
                'incomplete' => false,
                'base' => $computed['base'],
                'fee_net' => $charge->net_amount,
                'fee_tax' => $charge->tax_amount,
                'fee_gross' => $charge->amount,
            ],
        ];
    }

    public static function createCharge(Contract $contract, string $feeNet, string $dueDate): Charge
    {
        $rate = (string) config('fiscal.late_fee_tax', '0.00');
        $breakdown = BillingMath::applyTax($feeNet, $rate);

        return Charge::query()->create([
            'contract_id' => $contract->id,
            'contract_item_id' => null,
            'billing_period_id' => null,
            'charge_type' => ChargeType::LateFee,
            'period_start' => null,
            'period_end' => null,
            'net_amount' => $breakdown->net,
            'tax_rate_snapshot' => bccomp($rate, '0', 2) === 0 ? null : $rate,
            'tax_amount' => $breakdown->tax,
            'amount' => $breakdown->gross,
            'currency' => $contract->currency,
            'due_date' => $dueDate,
            'description' => 'Late fee',
        ]);
    }

    public static function priorFeeNets(Delinquency $case): string
    {
        $steps = DelinquencyStep::query()
            ->where('delinquency_id', $case->id)
            ->where('action', DelinquencyStepAction::AssessLateFee)
            ->whereNotNull('charge_id')
            ->with('charge')
            ->get();

        $sum = '0.00';
        foreach ($steps as $s) {
            $net = $s->charge?->net_amount;
            if ($net !== null) {
                $sum = bcadd($sum, (string) $net, 2);
            }
        }

        return BillingMath::round2($sum);
    }

    /** @return array<string, mixed>|null */
    private static function firstFeeParams(Delinquency $case): ?array
    {
        $case->loadMissing('policy.steps');
        $step = $case->policy?->steps
            ?->first(fn (DelinquencyPolicyStep $s): bool => (
                ($s->action instanceof DelinquencyPolicyAction
                    ? $s->action
                    : DelinquencyPolicyAction::from((string) $s->action)
                ) === DelinquencyPolicyAction::AssessLateFee
            ));

        return $step?->params;
    }
}
