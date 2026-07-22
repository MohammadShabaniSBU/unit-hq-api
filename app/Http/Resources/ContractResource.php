<?php

namespace App\Http\Resources;

use App\Models\ContractItem;
use App\Models\Discount;
use App\Models\Insurance;
use App\Models\Unit;
use Illuminate\Http\Request;

class ContractResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'contact_id'      => $this->contact_id,
            'reservation_id'  => $this->reservation_id,
            'deal_id'         => $this->deal_id,
            'start_date'      => $this->date($this->start_date),
            'end_date'        => $this->date($this->end_date),
            'billing_interval'       => $this->enumValue($this->billing_interval),
            'billing_interval_count' => $this->billing_interval_count,
            'billing_anchor_model'   => $this->enumValue($this->billing_anchor_model),
            'billing_anchor_date'    => $this->date($this->billing_anchor_date),
            'billed_through'         => $this->date($this->billed_through),
            'proration_method'       => $this->enumValue($this->proration_method),
            'move_in_date'           => $this->date($this->move_in_date),
            'deposit_amount'         => $this->deposit_amount,
            'status'          => $this->enumValue($this->status),
            'signed_at'       => $this->datetime($this->signed_at),
            'created_at'      => $this->datetime($this->created_at),
            'updated_at'      => $this->datetime($this->updated_at),
            'items'           => $this->whenLoaded('items', fn () =>
                $this->items->map(fn (ContractItem $item) => $this->formatItem($item))
            ),
            'contact'         => $this->whenLoaded('contact', fn () => [
                'id'   => $this->contact->id,
                'name' => trim($this->contact->first_name . ' ' . $this->contact->last_name),
            ]),
            'reservation'     => $this->whenLoaded('reservation', fn () =>
                $this->reservation ? [
                    'id'     => $this->reservation->id,
                    'status' => $this->reservation->status,
                ] : null
            ),
            'deal'            => $this->whenLoaded('deal', fn () =>
                $this->deal ? [
                    'id'     => $this->deal->id,
                    'status' => $this->deal->status,
                ] : null
            ),
            'notes'           => NoteResource::collection($this->whenLoaded('notes')),
            'invoices'        => $this->whenLoaded('invoices', fn () =>
                InvoiceResource::collection($this->invoices)->resolve()
            ),
            'payments'        => $this->whenLoaded('payments', fn () =>
                PaymentResource::collection($this->payments)->resolve()
            ),
            'charges'         => $this->whenLoaded('charges', fn () =>
                ChargeResource::collection($this->charges)->resolve()
            ),
            'billing_summary' => $this->when(
                $this->relationLoaded('invoices') && $this->relationLoaded('payments'),
                fn () => $this->billingSummary()
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function formatItem(ContractItem $contractItem): array
    {
        $data = [
            'id'                    => $contractItem->id,
            'item_type'             => $contractItem->item_type,
            'item_id'               => $contractItem->item_id,
            'amount'                => $contractItem->amount,
            'price_id'              => $contractItem->price_id,
            'discount_id'           => $contractItem->discount_id,
            'base_rate'             => $contractItem->base_rate,
            'discount_ends_at'      => $this->date($contractItem->discount_ends_at),
            'tax_rate_id'           => $contractItem->tax_rate_id,
            'tax_rate_snapshot'     => $contractItem->tax_rate_snapshot,
            'declared_goods_value'  => $contractItem->declared_goods_value,
            'description'           => $contractItem->description,
        ];

        if ($contractItem->relationLoaded('taxRate')) {
            $data['tax_rate'] = $contractItem->taxRate ? [
                'id'   => $contractItem->taxRate->id,
                'name' => $contractItem->taxRate->name,
                'code' => $contractItem->taxRate->code,
                'rate' => $contractItem->taxRate->rate,
            ] : null;
        }

        if ($contractItem->relationLoaded('discount')) {
            /** @var Discount|null $discount */
            $discount = $contractItem->discount;
            $data['discount'] = $discount ? [
                'id'            => $discount->id,
                'code'          => $discount->code,
                'label'         => $discount->label,
                'discount_type' => $discount->discount_type instanceof \BackedEnum
                    ? $discount->discount_type->value
                    : $discount->discount_type,
                'value'         => $discount->value,
            ] : null;
        }

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

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
