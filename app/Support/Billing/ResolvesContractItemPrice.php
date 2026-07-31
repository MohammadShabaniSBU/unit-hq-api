<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\InsuranceRate;
use App\Models\Price;
use App\Models\Unit;
use App\Models\UnitClassRate;
use Illuminate\Validation\ValidationException;

/**
 * Resolve the Price row a contract item version should reference at signing.
 * Catalogue match → reuse; amount differs → mint scope=contract price owned
 * by the same pairing.
 */
final class ResolvesContractItemPrice
{
    public static function forSigning(
        string $itemType,
        int $itemId,
        string $amount,
        ?int $siteId,
        ?int $createdBy,
        ?Price $preferred = null,
    ): Price {
        $amount = BillingMath::round2($amount);

        if ($preferred !== null && BillingMath::round2((string) $preferred->amount) === $amount) {
            return $preferred;
        }

        $rate = self::resolvePairing($itemType, $itemId, $siteId);
        if ($rate === null) {
            throw ValidationException::withMessages([
                'items' => ['No rate pairing found for the selected item.'],
            ]);
        }

        $catalogue = $rate->price;
        if ($catalogue !== null && BillingMath::round2((string) $catalogue->amount) === $amount) {
            return $catalogue;
        }

        if ($preferred !== null && BillingMath::round2((string) $preferred->amount) === $amount) {
            return $preferred;
        }

        // Negotiated amount — contract-scoped price, no window.
        return Price::query()->create([
            'priceable_type' => $rate instanceof UnitClassRate ? 'unit_class_rate' : 'insurance_rate',
            'priceable_id'   => $rate->id,
            'scope'          => Price::SCOPE_CONTRACT,
            'amount'         => $amount,
            'currency'       => $catalogue?->currency
                ?? $preferred?->currency
                ?? ResolvesItemCurrency::forItem($itemType, $itemId, $preferred?->id, $siteId),
            'effective_from' => null,
            'effective_to'   => null,
            'created_by'     => $createdBy,
        ]);
    }

    private static function resolvePairing(string $itemType, int $itemId, ?int $siteId): UnitClassRate|InsuranceRate|null
    {
        if ($itemType === 'unit') {
            $unit = Unit::query()->find($itemId);
            if ($unit === null) {
                return null;
            }

            return UnitClassRate::query()
                ->with('price')
                ->where('unit_class_id', $unit->unit_class_id)
                ->where('site_id', $siteId ?? $unit->site_id)
                ->first();
        }

        if ($itemType === 'insurance') {
            if ($siteId === null) {
                return null;
            }

            return InsuranceRate::query()
                ->with('price')
                ->where('insurance_id', $itemId)
                ->where('site_id', $siteId)
                ->first();
        }

        return null;
    }
}
