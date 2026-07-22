<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class OfferCardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deal_id' => $this->deal_id,
            'contact_id' => $this->contact_id,
            'status' => $this->status,
            'expires_at' => $this->datetime($this->expires_at),
            'sent_at' => $this->datetime($this->sent_at),
            'updated_at' => $this->datetime($this->updated_at),
            'options_count' => $this->whenCounted('options'),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => trim("{$this->contact->first_name} {$this->contact->last_name}"),
            ]),
        ];
    }
}
