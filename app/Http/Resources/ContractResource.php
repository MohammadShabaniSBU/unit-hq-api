<?php

namespace App\Http\Resources;

use App\Models\ContractItem;
use App\Models\Insurance;
use App\Models\Unit;
use Illuminate\Http\Request;

class ContractResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'contact_id'     => $this->contact_id,
            'reservation_id' => $this->reservation_id,
            'deal_id'        => $this->deal_id,
            'start_date'     => $this->date($this->start_date),
            'end_date'       => $this->date($this->end_date),
            'status'         => $this->status,
            'signed_at'      => $this->datetime($this->signed_at),
            'created_at'     => $this->datetime($this->created_at),
            'updated_at'     => $this->datetime($this->updated_at),
            'items'          => $this->whenLoaded('items', fn () =>
                $this->items->map(fn (ContractItem $item) => $this->formatItem($item))
            ),
            'contact'        => $this->whenLoaded('contact', fn () => [
                'id'   => $this->contact->id,
                'name' => trim($this->contact->first_name . ' ' . $this->contact->last_name),
            ]),
            'reservation'    => $this->whenLoaded('reservation', fn () =>
                $this->reservation ? [
                    'id'     => $this->reservation->id,
                    'status' => $this->reservation->status,
                ] : null
            ),
            'deal'           => $this->whenLoaded('deal', fn () =>
                $this->deal ? [
                    'id'     => $this->deal->id,
                    'status' => $this->deal->status,
                ] : null
            ),
            'notes'          => NoteResource::collection($this->whenLoaded('notes')),
        ];
    }

    /** @return array<string, mixed> */
    private function formatItem(ContractItem $contractItem): array
    {
        $data = [
            'id'        => $contractItem->id,
            'item_type' => $contractItem->item_type,
            'item_id'   => $contractItem->item_id,
            'rate'      => $contractItem->rate,
        ];

        if ($contractItem->relationLoaded('item')) {
            $item = $contractItem->item;

            if ($item instanceof Unit) {
                $data['item'] = [
                    'id'          => $item->id,
                    'unit_number' => $item->unit_number,
                    'site'        => $item->relationLoaded('site') ? [
                        'id'   => $item->site->id,
                        'name' => $item->site->name,
                    ] : null,
                    'unit_class'  => $item->relationLoaded('unitClass') ? [
                        'id'    => $item->unitClass->id,
                        'label' => $item->unitClass->label,
                        'code'  => $item->unitClass->code_slug,
                    ] : null,
                ];
            } elseif ($item instanceof Insurance) {
                $data['item'] = [
                    'id'       => $item->id,
                    'name'     => $item->name,
                    'coverage' => $item->coverage,
                    'currency' => $item->currency,
                ];
            } else {
                $data['item'] = null;
            }
        }

        return $data;
    }
}
