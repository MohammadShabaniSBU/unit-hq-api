<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DepositSettlementResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'contract_id'     => $this->contract_id,
            'outcome'         => $this->enumValue($this->outcome),
            'deposit_amount'  => $this->deposit_amount,
            'refunded_amount' => $this->refunded_amount,
            'currency'        => $this->currency,
            'payout_status'   => $this->enumValue($this->payout_status),
            'paid_at'         => $this->datetime($this->paid_at),
            'created_by'      => $this->created_by,
            'created_at'      => $this->datetime($this->created_at),
            'updated_at'      => $this->datetime($this->updated_at),
            'lines'           => $this->whenLoaded('lines', fn () =>
                $this->lines->map(fn ($line) => [
                    'id'        => $line->id,
                    'charge_id' => $line->charge_id,
                    'amount'    => $line->amount,
                    'currency'  => $line->currency,
                    'reason'    => $line->reason,
                    'created_at'=> $this->datetime($line->created_at),
                ])->values()->all()
            ),
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
