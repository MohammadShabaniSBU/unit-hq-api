<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Unit;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Billing\BillingMath;
use App\Support\Billing\RecurringBilling;
use App\Support\Time\SiteClock;

final class BillingNextChargeTool implements AgentTool
{
    use ResolvesOwnedContract;

    public function key(): string
    {
        return 'billing.next_charge';
    }

    public function description(): string
    {
        return 'Next due date and amount from the contract\'s snapshotted cadence and anchor.';
    }

    public function schema(): array
    {
        return [
            'contract_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Contract id; defaults to the principal\'s in-force contract',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Verified;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $contract = $this->resolveOwnedContract($principal, $arguments);
        if ($contract instanceof ToolResult) {
            return $contract;
        }

        $estimate = RecurringBilling::nextBillEstimate($contract);
        if ($estimate === null) {
            return ToolResult::ok(
                ['next' => null, 'contract_id' => $contract->id],
                'There is no upcoming charge on this contract.',
                new FactBag,
                entities: [EntityRef::contract($contract)],
            );
        }

        $amount = BillingMath::round2($estimate['amount']);
        $currency = $estimate['currency'];
        $due = $estimate['window']['start'];
        $formatted = MoneyDisplay::format($amount, $currency, $principal->locale);

        $contract->loadMissing(['unitItem.item.site']);
        $unit = $contract->unitItem?->item;
        $site = $unit instanceof Unit ? $unit->site : null;
        if ($site !== null) {
            SiteClock::today($site);
        }

        $facts = (new FactBag)->money($amount, $currency)->date($due);

        $entities = [EntityRef::contract($contract)];
        if ($site !== null) {
            $entities[] = EntityRef::site($site);
        }

        return ToolResult::ok(
            [
                'contract_id' => $contract->id,
                'due_date' => $due,
                'period_end' => $estimate['window']['end'],
                'amount' => $amount,
                'currency' => $currency,
            ],
            "Next charge {$formatted} due {$due}.",
            $facts,
            entities: $entities,
        );
    }
}
