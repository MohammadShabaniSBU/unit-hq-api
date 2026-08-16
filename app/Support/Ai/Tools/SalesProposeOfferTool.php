<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Discount;
use App\Models\Setting;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Billing\BillingMath;
use App\Support\Discounts\DiscountSurface;
use App\Support\Fiscal\TaxResolver;
use Illuminate\Validation\ValidationException;

final class SalesProposeOfferTool implements AgentTool
{
    public function key(): string
    {
        return 'sales.propose_offer';
    }

    public function description(): string
    {
        return 'Build a structured catalogue proposal (line items, tax, discount label, term). Persists nothing — no Offer row, no token, no send.';
    }

    public function schema(): array
    {
        return [
            'contact_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional contact to name on the proposal',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Site id',
            ],
            'unit_class_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Unit class id',
            ],
            'discount_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Catalogue discount id to label',
            ],
            'move_in_date' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Proposed move-in date YYYY-MM-DD',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $site = Site::query()->find((int) $arguments['site_id']);
        $class = UnitClass::query()->find((int) $arguments['unit_class_id']);
        if ($site === null || $class === null) {
            return ToolResult::notFound('Site or unit class not found.');
        }

        $rate = UnitClassRate::query()
            ->where('site_id', $site->id)
            ->where('unit_class_id', $class->id)
            ->with('price')
            ->first();
        $price = $rate?->price;
        if ($price === null) {
            return ToolResult::notFound('No current catalogue price for that class at this site.');
        }

        try {
            $taxRate = TaxResolver::resolve(null, $class->tax_rate_code, $site);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Tax rate could not be resolved for this jurisdiction.';

            return ToolResult::error((string) $message, HandoffReason::Error);
        }

        $ratePct = $taxRate !== null ? (string) $taxRate->rate : '0.00';
        $breakdown = BillingMath::applyTax((string) $price->amount, $ratePct);
        $currency = (string) $price->currency;
        $billing = Setting::billing();
        $count = $billing->defaultBillingIntervalCount;
        $interval = $billing->defaultBillingInterval;
        $term = $count === 1 ? "every {$interval}" : "every {$count} {$interval}s";

        $discountLabel = null;
        $discountId = isset($arguments['discount_id']) ? (int) $arguments['discount_id'] : null;
        if ($discountId !== null) {
            $discount = Discount::query()->active()->whereKey($discountId)->first();
            if ($discount === null) {
                return ToolResult::notFound('Catalogue discount not found.');
            }
            $resolved = DiscountSurface::resolve($discount, locale: $principal->locale);
            $discountLabel = $resolved['promo_line'] ?? $discount->name;
        }

        $moveIn = isset($arguments['move_in_date']) ? (string) $arguments['move_in_date'] : null;
        $priceDisplay = MoneyDisplay::withTax($breakdown, $currency, $principal->locale, $ratePct);

        $facts = (new FactBag)
            ->money($breakdown->net, $currency)
            ->money($breakdown->tax, $currency)
            ->money($breakdown->gross, $currency)
            ->number($ratePct);
        if ($moveIn !== null && $moveIn !== '') {
            $facts->date($moveIn);
        }

        $bits = [
            "Proposal for {$class->label} at {$site->name}: {$priceDisplay}, billed {$term}.",
        ];
        if ($discountLabel !== null) {
            $bits[] = "Catalogue discount: {$discountLabel}.";
        }
        if ($moveIn !== null && $moveIn !== '') {
            $bits[] = "Proposed move-in {$moveIn}.";
        }
        $bits[] = 'Nothing has been sent or saved.';

        return ToolResult::ok(
            [
                'site_id' => $site->id,
                'unit_class_id' => $class->id,
                'contact_id' => isset($arguments['contact_id']) ? (int) $arguments['contact_id'] : null,
                'line_items' => [
                    [
                        'label' => $class->label,
                        'net' => $breakdown->net,
                        'tax' => $breakdown->tax,
                        'gross' => $breakdown->gross,
                        'rate' => $ratePct,
                        'currency' => $currency,
                    ],
                ],
                'net' => $breakdown->net,
                'tax' => $breakdown->tax,
                'gross' => $breakdown->gross,
                'currency' => $currency,
                'discount_id' => $discountId,
                'discount_label' => $discountLabel,
                'term' => $term,
                'move_in_date' => $moveIn,
                'persisted' => false,
            ],
            implode(' ', $bits),
            $facts,
        );
    }
}
