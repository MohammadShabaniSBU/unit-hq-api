<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;

class InvoiceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
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
}
