<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ContractItem;
use App\Models\Unit;
use Illuminate\Http\Request;

class BillingPeriodResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = null;

        if ($this->relationLoaded('contract') && $this->contract !== null) {
            $currency = $this->contract->currency;
        } elseif ($this->relationLoaded('charges')) {
            $currency = $this->charges->first()?->currency;
        }

        return [
            'id'                   => $this->id,
            'contract_id'          => $this->contract_id,
            'billing_period_start' => $this->date($this->billing_period_start),
            'billing_period_end'   => $this->date($this->billing_period_end),
            'status'               => $this->status,
            'issued_at'            => $this->datetime($this->issued_at),
            'created_at'           => $this->datetime($this->created_at),
            'currency'             => $currency,
            'total'                => $this->whenLoaded(
                'charges',
                fn () => number_format((float) $this->charges->sum('amount'), 2, '.', '')
            ),
            'charges_count'        => $this->whenLoaded('charges', fn () => $this->charges->count()),
            'contract'             => $this->whenLoaded('contract', fn () => $this->formatContractSummary()),
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
            'currency'    => $this->contract->currency,
            'unit_number' => $unitNumber,
        ];
    }
}
