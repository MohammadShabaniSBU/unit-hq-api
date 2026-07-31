<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InvoiceLineResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'charge_id' => $this->charge_id,
            'description' => $this->description,
            'period_start' => $this->date($this->period_start),
            'period_end' => $this->date($this->period_end),
            'net_amount' => $this->net_amount,
            'tax_rate_snapshot' => $this->tax_rate_snapshot,
            'tax_amount' => $this->tax_amount,
            'gross_amount' => $this->gross_amount,
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
