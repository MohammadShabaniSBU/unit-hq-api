<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ChargeResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'contract_id'           => $this->contract_id,
            'contract_item_id'      => $this->contract_item_id,
            'invoice_id'            => $this->invoice_id,
            'charge_type'           => $this->charge_type,
            'period_start'          => $this->date($this->period_start),
            'period_end'            => $this->date($this->period_end),
            'net_amount'            => $this->net_amount,
            'tax_rate_snapshot'     => $this->tax_rate_snapshot,
            'tax_amount'            => $this->tax_amount,
            'amount'                => $this->amount,
            'due_date'              => $this->date($this->due_date),
            'description'           => $this->description,
            'reversal_of_charge_id' => $this->reversal_of_charge_id,
            'created_at'            => $this->datetime($this->created_at),
        ];
    }
}
