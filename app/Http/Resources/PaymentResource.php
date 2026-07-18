<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContractItem;
use App\Models\Unit;
use Illuminate\Http\Request;

class PaymentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'contract_id'              => $this->contract_id,
            'amount'                   => $this->amount,
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'reversal_of_payment_id'   => $this->reversal_of_payment_id,
            'created_at'               => $this->datetime($this->created_at),
            'allocated_amount'         => $this->whenLoaded(
                'allocations',
                fn () => number_format((float) $this->allocations->sum('amount'), 2, '.', '')
            ),
            'contract'                 => $this->whenLoaded('contract', fn () => $this->formatContractSummary()),
        ];
    }

    /** @return array<string, mixed> */
    private function formatContractSummary(): array
    {
        $unitNumber = null;

        if ($this->contract->relationLoaded('items')) {
            /** @var ContractItem|null $unitItem */
            $unitItem = $this->contract->items->firstWhere('item_type', 'unit');

            if ($unitItem !== null && $unitItem->relationLoaded('item') && $unitItem->item instanceof Unit) {
                $unitNumber = $unitItem->item->unit_number;
            }
        }

        return [
            'id'          => $this->contract->id,
            'status'      => $this->contract->status,
            'unit_number' => $unitNumber,
        ];
    }
}
