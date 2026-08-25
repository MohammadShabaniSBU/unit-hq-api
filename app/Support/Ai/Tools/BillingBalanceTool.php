<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contract;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Billing\BillingMath;

final class BillingBalanceTool implements AgentTool
{
    public function key(): string
    {
        return 'billing.balance';
    }

    public function description(): string
    {
        return 'Open balance per currency for the principal. Never a single summed figure across currencies.';
    }

    public function schema(): array
    {
        return [
            'contact_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Contact id; defaults to the principal',
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
        if ($principal->contactId === null) {
            return ToolResult::denied(
                ToolDeniedReason::Ownership,
                'This tool requires a contact principal.',
            );
        }

        $contactId = isset($arguments['contact_id']) ? (int) $arguments['contact_id'] : $principal->contactId;
        if (! $principal->ownsContact($contactId)) {
            return ToolResult::denied(
                ToolDeniedReason::Ownership,
                'Argument [contact_id] does not belong to this principal.',
            );
        }

        $contracts = Contract::query()->where('contact_id', $contactId)->get();
        $byCurrency = [];
        foreach ($contracts as $contract) {
            $currency = (string) $contract->currency;
            if ($currency === '') {
                continue;
            }
            $byCurrency[$currency] = BillingMath::round2(bcadd(
                $byCurrency[$currency] ?? '0.00',
                BillingMath::round2($contract->balanceOwed()),
                2,
            ));
        }

        $facts = new FactBag;
        $lines = [];
        $entries = [];
        foreach ($byCurrency as $currency => $amount) {
            $facts->money($amount, $currency);
            $formatted = MoneyDisplay::format($amount, $currency, $principal->locale);
            $entries[$currency] = $amount;
            $lines[] = "{$formatted} open in {$currency}";
        }

        $display = $lines === []
            ? 'There is no open balance.'
            : 'Open balance: '.implode('; ', $lines).'. These amounts are not added together.';

        $contact = \App\Models\Contact::query()->find($contactId);
        $entities = $contact !== null ? [EntityRef::contact($contact)] : [];

        return ToolResult::ok(
            ['balances' => $entries, 'contact_id' => $contactId],
            $display,
            $facts,
            entities: $entities,
        );
    }
}
