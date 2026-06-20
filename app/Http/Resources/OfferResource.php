<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class OfferResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'deal_id'         => $this->deal_id,
            'contact_id'      => $this->contact_id,
            'token'           => $this->token,
            'status'          => $this->status,
            'expires_at'      => $this->datetime($this->expires_at),
            'sent_at'         => $this->datetime($this->sent_at),
            'first_viewed_at' => $this->datetime($this->first_viewed_at),
            'accepted_at'     => $this->datetime($this->accepted_at),
            'created_at'      => $this->datetime($this->created_at),
            'updated_at'      => $this->datetime($this->updated_at),
            'options'         => OfferOptionResource::collection($this->whenLoaded('options')),
            'deal'            => DealResource::make($this->whenLoaded('deal')),
            'contact'         => $this->whenLoaded('contact', fn () => [
                'id'   => $this->contact->id,
                'name' => trim($this->contact->first_name . ' ' . $this->contact->last_name),
            ]),
            'notes'           => NoteResource::collection($this->whenLoaded('notes')),
        ];
    }
}
