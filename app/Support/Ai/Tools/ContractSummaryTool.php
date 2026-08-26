<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\BillingInterval;
use App\Enums\ContractStatus;
use App\Models\Unit;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Billing\BillingMath;

final class ContractSummaryTool implements AgentTool
{
    use ResolvesOwnedContract;

    public function key(): string
    {
        return 'contract.summary';
    }

    public function description(): string
    {
        return 'Summarise the principal\'s contract: unit identifier, site, start date, cadence, current rate, status.';
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

    public function retainInSummary(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [
            'contract_id' => EntityType::Contract,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $contract = $this->resolveOwnedContract($principal, $arguments);
        if ($contract instanceof ToolResult) {
            return $contract;
        }

        $contract->loadMissing(['unitItem.item.site', 'unitItem.price']);
        $item = $contract->unitItem;
        $unit = $item?->item;
        $site = $unit instanceof Unit ? $unit->site : null;
        $price = $item?->price;

        $interval = $contract->billing_interval instanceof BillingInterval
            ? $contract->billing_interval->value
            : (string) $contract->billing_interval;
        $count = (int) $contract->billing_interval_count;
        $cadence = $count === 1 ? "every {$interval}" : "every {$count} {$interval}s";
        $status = $contract->status instanceof ContractStatus
            ? $contract->status->value
            : (string) $contract->status;
        $start = $contract->start_date instanceof \DateTimeInterface
            ? $contract->start_date->format('Y-m-d')
            : (string) $contract->start_date;
        $unitNumber = $unit instanceof Unit ? (string) $unit->unit_number : null;
        $currency = $price !== null ? (string) $price->currency : (string) $contract->currency;
        $amount = $price !== null ? BillingMath::round2((string) $price->amount) : null;
        $rateDisplay = $amount !== null ? MoneyDisplay::format($amount, $currency, $principal->locale) : 'unknown';

        $facts = (new FactBag)->date($start)->identifier((string) $contract->id);
        if ($unitNumber !== null) {
            $facts->identifier($unitNumber);
        }
        if ($amount !== null) {
            $facts->money($amount, $currency);
        }

        $siteName = $site?->name ?? 'unknown site';
        $unitBit = $unitNumber !== null ? "unit {$unitNumber}" : 'a unit';
        $display = "Contract {$contract->id} at {$siteName}, {$unitBit}, started {$start}, billed {$cadence} at {$rateDisplay}, status {$status}.";

        $entities = [EntityRef::contract($contract, $site?->name)];
        if ($site !== null) {
            $entities[] = EntityRef::site($site);
        }
        if ($unit instanceof Unit) {
            $entities[] = EntityRef::unit($unit, $site?->name);
        }

        return ToolResult::ok(
            [
                'contract_id' => $contract->id,
                'unit_number' => $unitNumber,
                'site_id' => $site?->id,
                'site_name' => $site?->name,
                'start_date' => $start,
                'cadence' => $cadence,
                'rate' => $amount,
                'currency' => $currency,
                'status' => $status,
            ],
            $display,
            $facts,
            entities: $entities,
        );
    }
}
