<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PaymentMethodResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'type' => $this->type?->value,
            'stripe_pm_id' => $this->stripe_pm_id,
            'payment_provider_account_id' => $this->payment_provider_account_id,
            'display_label' => $this->display_label,
            'is_default' => (bool) $this->is_default,
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
