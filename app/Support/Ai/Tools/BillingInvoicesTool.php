<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Billing\BillingMath;

final class BillingInvoicesTool implements AgentTool
{
    public function key(): string
    {
        return 'billing.invoices';
    }

    public function description(): string
    {
        return 'List recent issued invoices for the principal: number, date, gross, currency, status. No PDF or download link.';
    }

    public function schema(): array
    {
        return [
            'contact_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Contact id; defaults to the principal',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'How many invoices to return (default 5, max 20)',
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

        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 5;
        if ($limit < 1) {
            $limit = 5;
        }
        if ($limit > 20) {
            $limit = 20;
        }

        $invoices = Invoice::query()
            ->where('contact_id', $contactId)
            ->where('status', InvoiceStatus::Issued)
            ->latest('issue_date')
            ->latest('id')
            ->limit($limit)
            ->get();

        $facts = new FactBag;
        $rows = [];
        $lines = [];
        $entities = [];
        $contact = \App\Models\Contact::query()->find($contactId);
        if ($contact !== null) {
            $entities[] = EntityRef::contact($contact);
        }
        foreach ($invoices as $invoice) {
            $gross = BillingMath::round2((string) $invoice->gross_total);
            $currency = (string) $invoice->currency;
            $date = $invoice->issue_date instanceof \DateTimeInterface
                ? $invoice->issue_date->format('Y-m-d')
                : (string) $invoice->issue_date;
            $status = $invoice->status instanceof InvoiceStatus
                ? $invoice->status->value
                : (string) $invoice->status;
            $formatted = MoneyDisplay::format($gross, $currency, $principal->locale);
            $facts->money($gross, $currency)->date($date)->identifier((string) $invoice->full_number);
            $rows[] = [
                'id' => $invoice->id,
                'number' => $invoice->full_number,
                'date' => $date,
                'gross' => $gross,
                'currency' => $currency,
                'status' => $status,
            ];
            $lines[] = "{$invoice->full_number} on {$date}: {$formatted} ({$status})";
            $entities[] = EntityRef::invoice($invoice);
        }

        $display = $lines === []
            ? 'No issued invoices found.'
            : 'Issued invoices: '.implode('; ', $lines).'.';

        return ToolResult::ok(
            ['invoices' => $rows, 'contact_id' => $contactId],
            $display,
            $facts,
            entities: $entities,
        );
    }
}
