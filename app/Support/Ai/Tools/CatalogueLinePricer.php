<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Discount;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Billing\BillingMath;
use App\Support\Billing\TaxBreakdown;
use App\Support\Discounts\DiscountSurface;
use App\Support\Fiscal\TaxResolver;
use App\Support\Time\SiteClock;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue price + tax + optional discount label for one unit-class rate.
 * Shared by sales.propose_offer and sales.create_offer — do not copy this path.
 */
final readonly class CatalogueLinePricer
{
    public function __construct(
        public string $net,
        public string $tax,
        public string $gross,
        public string $ratePct,
        public string $currency,
        public string $display,
        public ?int $discountId,
        public ?string $discountLabel,
        public TaxBreakdown $breakdown,
        public int $priceId,
        public ?int $taxRateId,
    ) {}

    public static function price(
        UnitClassRate $rate,
        UnitClass $class,
        Site $site,
        AgentPrincipal $principal,
        FactRegistry $registry,
        ?int $discountId = null,
    ): self|ToolResult {
        if (! $registry->contains(EntityType::UnitClass, $class->id)) {
            $message = json_encode([
                'argument' => 'unit_class_id',
                'value' => $class->id,
                'type' => EntityType::UnitClass->value,
                'licensed' => $registry->ids(EntityType::UnitClass),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'unlicensed_argument';

            return ToolResult::denied(
                ToolDeniedReason::UnlicensedArgument,
                $message,
                ToolError::unlicensedArgument($message, [
                    'tool' => 'facility.availability',
                    'hint' => 'call facility.availability without a unit_class_id to list licensed classes',
                ]),
            );
        }
        $price = $rate->price;
        if ($price === null) {
            return ToolResult::notFound('No current catalogue price for that class at this site.');
        }

        try {
            $taxRate = TaxResolver::resolve(null, $class->tax_rate_code, $site, SiteClock::today($site));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Tax rate could not be resolved for this jurisdiction.';

            return ToolResult::error((string) $message, HandoffReason::Error);
        }

        $ratePct = $taxRate !== null ? (string) $taxRate->rate : '0.00';
        $breakdown = BillingMath::applyTax((string) $price->amount, $ratePct);
        $currency = (string) $price->currency;

        $discountLabel = null;
        if ($discountId !== null) {
            $discount = Discount::query()->active()->whereKey($discountId)->first();
            if ($discount === null) {
                return ToolResult::notFound('Catalogue discount not found.');
            }
            $resolved = DiscountSurface::resolve($discount, locale: $principal->locale);
            $discountLabel = $resolved['promo_line'] ?? $discount->name;
        }

        return new self(
            $breakdown->net,
            $breakdown->tax,
            $breakdown->gross,
            $ratePct,
            $currency,
            MoneyDisplay::withTax($breakdown, $currency, $principal->locale, $ratePct),
            $discountId,
            $discountLabel,
            $breakdown,
            $price->id,
            $taxRate?->id,
        );
    }
}
