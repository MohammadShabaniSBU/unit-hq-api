<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;

class ContractCardResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'deal_id' => $this->deal_id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'currency' => $this->currency,
            'start_date' => $this->date($this->start_date),
            'end_date' => $this->date($this->end_date),
            'signed_at' => $this->datetime($this->signed_at),
            'updated_at' => $this->datetime($this->updated_at),
            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => trim("{$this->contact->first_name} {$this->contact->last_name}"),
            ]),
            'unit' => $this->whenLoaded('unitItem', function () {
                $item = $this->unitItem?->item;

                if (! $item instanceof Unit) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'unit_number' => $item->unit_number,
                    'amount' => $this->unitItem?->amount,
                    'currency' => $this->unitItem?->currency ?? $this->currency,
                    'site' => $item->relationLoaded('site') && $item->site !== null
                        ? [
                            'id' => $item->site->id,
                            'name' => $item->site->name,
                        ]
                        : null,
                ];
            }),
        ];
    }
}
