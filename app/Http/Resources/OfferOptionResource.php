<?php

namespace App\Http\Resources;

use App\Models\Deal;
use App\Support\Discounts\DiscountSurface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class OfferOptionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $discountPayload = null;
        $resolution = null;
        $promoLine = null;

        if ($this->relationLoaded('discount') && $this->discount !== null) {
            $discount = $this->discount;
            $discountPayload = [
                'id' => $discount->id,
                'name' => $discount->name,
                'kind' => $discount->kind instanceof \BackedEnum
                    ? $discount->kind->value
                    : $discount->kind,
                'params' => $discount->params ?? [],
                'tracks_rate_changes' => (bool) $discount->tracks_rate_changes,
            ];

            $this->loadMissing('offer.deal', 'unitClassRate.price');

            /** @var Deal|null $deal */
            $deal = $this->offer?->deal;
            $price = $this->unitClassRate?->price;
            $listAmount = $price?->amount !== null
                ? (string) $price->amount
                : null;

            $resolution = DiscountSurface::resolve(
                discount: $discount,
                deal: $deal,
                listAmount: $listAmount,
                currency: $price?->currency !== null ? (string) $price->currency : null,
                locale: App::getLocale(),
            );
            $promoLine = $resolution['promo_line'];
        }

        return [
            'id'                 => $this->id,
            'offer_id'           => $this->offer_id,
            'unit_class_rate_id' => $this->unit_class_rate_id,
            'unit_id'            => $this->unit_id,
            'discount_id'        => $this->discount_id,
            'label'              => $this->label,
            'description'        => $this->description,
            'display_order'      => $this->display_order,
            'selected_at'        => $this->datetime($this->selected_at),
            'created_at'         => $this->datetime($this->created_at),
            'updated_at'         => $this->datetime($this->updated_at),
            'unit_class_rate'    => UnitClassRateResource::make($this->whenLoaded('unitClassRate')),
            'unit'               => UnitResource::make($this->whenLoaded('unit')),
            'discount'           => $discountPayload,
            'discount_resolution' => $resolution,
            'promo_line'         => $promoLine,
        ];
    }
}
