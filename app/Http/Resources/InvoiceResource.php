<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Support\Billing\BillingMath;
use Illuminate\Http\Request;

class InvoiceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $paymentState = $this->computedPaymentState();

        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'invoice_series_id' => $this->invoice_series_id,
            'number' => $this->number,
            'full_number' => $this->full_number,
            'kind' => $this->kind?->value ?? $this->kind,
            'status' => $this->status?->value ?? $this->status,
            'issue_date' => $this->date($this->issue_date),
            'contract_id' => $this->contract_id,
            'contact_id' => $this->contact_id,
            'rectifies_invoice_id' => $this->rectifies_invoice_id,
            'rectification_reason' => $this->rectification_reason,
            'issuer_name' => $this->issuer_name,
            'issuer_tax_id' => $this->issuer_tax_id,
            'issuer_address' => $this->issuer_address,
            'buyer_name' => $this->buyer_name,
            'buyer_tax_id' => $this->buyer_tax_id,
            'buyer_address' => $this->buyer_address,
            'currency' => $this->currency,
            'net_total' => $this->net_total,
            'tax_total' => $this->tax_total,
            'gross_total' => $this->gross_total,
            'paid_amount' => $paymentState['paid_amount'],
            'outstanding_amount' => $paymentState['outstanding_amount'],
            'payment_status' => $paymentState['payment_status'],
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
            'contact' => $this->whenLoaded('contact', fn () => [
                'id' => $this->contact->id,
                'name' => trim(($this->contact->first_name ?? '').' '.($this->contact->last_name ?? '')),
            ]),
            'contract' => $this->whenLoaded('contract', fn () => $this->contract === null ? null : [
                'id' => $this->contract->id,
            ]),
            'rectifies_invoice' => $this->whenLoaded('rectifiesInvoice', fn () => $this->rectifiesInvoice === null ? null : [
                'id' => $this->rectifiesInvoice->id,
                'full_number' => $this->rectifiesInvoice->full_number,
                'kind' => $this->rectifiesInvoice->kind?->value ?? $this->rectifiesInvoice->kind,
                'gross_total' => $this->rectifiesInvoice->gross_total,
            ]),
            'rectificatives' => $this->whenLoaded('rectificatives', fn () => $this->rectificatives->map(fn (Invoice $r) => [
                'id' => $r->id,
                'full_number' => $r->full_number,
                'kind' => $r->kind?->value ?? $r->kind,
                'gross_total' => $r->gross_total,
            ])->values()->all()),
            'lines' => $this->whenLoaded('lines', fn () => InvoiceLineResource::collection($this->lines)->resolve()),
        ];
    }

    /**
     * Paid / outstanding from live allocations on invoice charges — never stored.
     *
     * @return array{paid_amount: string, outstanding_amount: string, payment_status: string}
     */
    private function computedPaymentState(): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        if ($invoice->relationLoaded('charges')) {
            $charges = $invoice->charges;
            $charges->loadMissing('allocations');
            $paid = '0.00';
            foreach ($charges as $charge) {
                $paid = bcadd($paid, $charge->allocatedAmount(), 2);
            }
        } else {
            $paid = number_format(
                (float) $invoice->charges()->withSum('allocations', 'amount')->get()
                    ->sum(fn ($c) => (float) ($c->allocations_sum_amount ?? 0)),
                2,
                '.',
                ''
            );
        }

        $gross = BillingMath::round2((string) $invoice->gross_total);
        // Credit invoices (negative gross): treat as paid when allocations cover the absolute.
        if (bccomp($gross, '0', 2) < 0) {
            $absGross = bcmul($gross, '-1', 2);
            $absPaid = bccomp($paid, '0', 2) < 0 ? bcmul($paid, '-1', 2) : $paid;
            $outstanding = bccomp($absPaid, $absGross, 2) >= 0 ? '0.00' : bcsub($absGross, $absPaid, 2);
            $status = match (true) {
                bccomp($absPaid, '0', 2) <= 0 => 'unpaid',
                bccomp($outstanding, '0', 2) <= 0 => 'paid',
                default => 'partial',
            };

            return [
                'paid_amount' => BillingMath::round2($paid),
                'outstanding_amount' => $outstanding,
                'payment_status' => $status,
            ];
        }

        $clampedPaid = bccomp($paid, $gross, 2) > 0 ? $gross : BillingMath::round2($paid);
        if (bccomp($clampedPaid, '0', 2) < 0) {
            $clampedPaid = '0.00';
        }
        $outstanding = bcsub($gross, $clampedPaid, 2);
        $status = match (true) {
            bccomp($clampedPaid, '0', 2) <= 0 => 'unpaid',
            bccomp($outstanding, '0', 2) <= 0 => 'paid',
            default => 'partial',
        };

        return [
            'paid_amount' => $clampedPaid,
            'outstanding_amount' => $outstanding,
            'payment_status' => $status,
        ];
    }
}
