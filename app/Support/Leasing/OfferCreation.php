<?php

declare(strict_types=1);

namespace App\Support\Leasing;

use App\Enums\AttributeEntityType;
use App\Models\Offer;
use App\Models\Unit;
use App\Support\Attributes\AppliesCreateAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OfferCreation
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $options
     * @param  list<array{definition_id: int|string, value: mixed}>  $customAttributes
     */
    public static function create(
        array $attributes,
        array $options,
        array $customAttributes,
        LeasingActor $actor,
    ): Offer {
        return DB::transaction(function () use ($attributes, $options, $customAttributes, $actor): Offer {
            $attributes['token'] ??= Str::random(64);

            $offer = Offer::query()->create($attributes);

            foreach ($options as $optionData) {
                $optionData['unit_id'] = Unit::resolveUnitIdForRate($optionData['unit_class_rate_id']);
                $offer->options()->create($optionData);
            }

            AppliesCreateAttributes::apply(
                AttributeEntityType::Offer,
                $offer,
                $customAttributes,
                $actor->employee,
            );

            return $offer;
        });
    }
}
