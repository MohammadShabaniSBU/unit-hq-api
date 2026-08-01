<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PaymentRequestResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'url' => $this->publicPath(),
            'contract_id' => $this->contract_id,
            'payment_provider_account_id' => $this->payment_provider_account_id,
            'charge_ids' => array_map('intval', $this->charge_ids ?? []),
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status?->value,
            'expired' => $this->isExpired(),
            'expires_at' => $this->datetime($this->expires_at),
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'save_card_requested' => (bool) $this->save_card_requested,
            'paid_payment_id' => $this->paid_payment_id,
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
