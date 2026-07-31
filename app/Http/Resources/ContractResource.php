<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Discount;
use App\Models\Insurance;
use App\Models\Unit;
use App\Models\UnitOccupancy;
use App\Support\Contracts\ContractTransition;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            'currency'               => $this->currency,
            'status'          => $this->enumValue($this->status),
            'allowed_transitions' => ContractTransition::allowed($this->contract()),
            'can_transfer'    => ContractTransition::canTransfer($this->contract()),
            'notice_given_on' => $this->date($this->notice_given_on),
            'notice_period_days' => $this->notice_period_days,
            'scheduled_move_out_on' => $this->date($this->scheduled_move_out_on),
            'move_out_settlement' => $this->enumValue($this->move_out_settlement),
            'transfer_billing' => $this->enumValue($this->transfer_billing),
            'move_out_on'     => $this->date($this->move_out_on),
            'ended_reason'    => $this->enumValue($this->ended_reason),
            'deposit_settlement' => $this->whenLoaded('depositSettlement', fn () =>
                $this->depositSettlement
                    ? DepositSettlementResource::make($this->depositSettlement)->resolve()
                    : null
            ),
            'signed_at'       => $this->datetime($this->signed_at),
            'created_at'      => $this->datetime($this->created_at),
            'updated_at'      => $this->datetime($this->updated_at),
            'items'           => $this->whenLoaded('items', fn () =>
                $this->items->map(fn (ContractItem $item) => $this->formatItem($item))->values()->all()
            ),
            'item_history'    => $this->when(
                $this->relationLoaded('itemHistory'),
                fn () => Collection::make($this->itemHistory)
                    ->map(fn (ContractItem $item) => $this->formatItem($item))
                    ->values()
                    ->all()
            ),
            'occupancies'     => $this->whenLoaded('occupancies', fn () =>
                $this->occupancies->map(fn (UnitOccupancy $occupancy) => [
                    'unit_id'      => $occupancy->unit_id,
                    'unit_number'  => $occupancy->relationLoaded('unit')
                        ? $occupancy->unit?->unit_number
                        : null,
                    'started_on'   => $this->date($occupancy->started_on),
                    'ended_on'     => $this->date($occupancy->ended_on),
                    'ended_reason' => $occupancy->ended_reason,
                ])->values()->all()
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
            'billing_periods' => $this->whenLoaded('billingPeriods', fn () =>
                BillingPeriodResource::collection($this->billingPeriods)->resolve()
            ),
            'payments'        => $this->whenLoaded('payments', fn () =>
                PaymentResource::collection($this->payments)->resolve()
            ),
            'charges'         => $this->whenLoaded('charges', fn () =>
                ChargeResource::collection($this->charges)->resolve()
            ),
            'billing_summary' => $this->when(
                $this->relationLoaded('billingPeriods') && $this->relationLoaded('payments'),
                fn () => $this->billingSummary()
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function formatItem(ContractItem $contractItem): array
    {
        $contractItem->loadMissing('price');

        $data = [
            'id'                    => $contractItem->id,
            'item_type'             => $contractItem->item_type,
            'item_id'               => $contractItem->item_id,
            'amount'                => $contractItem->price?->amount,
            'currency'              => $contractItem->price?->currency,
            'price_id'              => $contractItem->price_id,
            'discount_id'           => $contractItem->discount_id,
            'base_rate'             => $contractItem->base_rate,
            'discount_ends_at'      => $this->date($contractItem->discount_ends_at),
            'tax_rate_id'           => $contractItem->tax_rate_id,
            'tax_rate_snapshot'     => $contractItem->tax_rate_snapshot,
            'declared_goods_value'  => $contractItem->declared_goods_value,
            'description'           => $contractItem->description,
            'effective_from'        => $this->date($contractItem->effective_from),
            'effective_to'          => $this->date($contractItem->effective_to),
            'supersedes_id'         => $contractItem->supersedes_id,
            'change_reason'         => $this->enumValue($contractItem->change_reason),
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

    private function contract(): Contract
    {
        /** @var Contract $contract */
        $contract = $this->resource;

        return $contract;
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
