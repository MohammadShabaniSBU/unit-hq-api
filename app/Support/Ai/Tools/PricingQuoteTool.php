<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Setting;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\HandoffReason;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Billing\BillingMath;
use App\Support\Fiscal\TaxResolver;
use App\Support\Time\SiteClock;
use Illuminate\Validation\ValidationException;

final class PricingQuoteTool implements AgentTool
{
    public function key(): string
    {
        return 'pricing.quote';
    }

    public function description(): string
    {
        return 'Catalogue price for a unit class at a site, with exclusive tax, named price_id, and billing period. Never guess a tax rate.';
    }

    public function schema(): array
    {
        return [
            'unit_class_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Unit class id',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Site id',
            ],
            'discount_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional catalogue discount id to label; does not change the quoted list price',
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

    public function retainInSummary(): bool
    {
        return true;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [
            'unit_class_id' => EntityType::UnitClass,
            'site_id' => EntityType::Site,
            'discount_id' => EntityType::Discount,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $classId = (int) $arguments['unit_class_id'];
        $siteId = (int) $arguments['site_id'];

        $site = Site::query()->find($siteId);
        $class = UnitClass::query()->find($classId);
        if ($site === null || $class === null) {
            return ToolResult::notFound('Site or unit class not found.');
        }

        $rate = UnitClassRate::query()
            ->where('site_id', $siteId)
            ->where('unit_class_id', $classId)
            ->with('price')
            ->first();
        $price = $rate?->price;
        if ($price === null) {
            return ToolResult::notFound('No current catalogue price for that class at this site.');
        }

        $asOf = SiteClock::today($site);

        try {
            $taxRate = TaxResolver::resolve(null, $class->tax_rate_code, $site, $asOf);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Tax rate could not be resolved for this jurisdiction.';

            return ToolResult::error((string) $message, HandoffReason::Error);
        }

        $ratePct = $taxRate !== null ? (string) $taxRate->rate : '0.00';
        $breakdown = BillingMath::applyTax((string) $price->amount, $ratePct);
        $currency = (string) $price->currency;
        $billing = Setting::billing();
        $period = MoneyDisplay::billingPeriod(
            $billing->defaultBillingInterval,
            $billing->defaultBillingIntervalCount,
        );
        $asOfDate = $asOf->toDateString();
        $label = (string) $class->label;
        $display = MoneyDisplay::quote(
            $breakdown,
            $currency,
            $principal->locale,
            $ratePct,
            $period,
            $label,
            $site->name,
        );

        $facts = (new FactBag)
            ->money($breakdown->net, $currency)
            ->money($breakdown->tax, $currency)
            ->money($breakdown->gross, $currency)
            ->number($ratePct)
            ->percent($ratePct)
            ->date($asOfDate);

        return ToolResult::ok(
            [
                'price_id' => $price->id,
                'unit_class_id' => $classId,
                'unit_class_label' => $label,
                'label' => $label,
                'size' => $class->size !== null ? (string) $class->size : null,
                'site_id' => $siteId,
                'site_name' => $site->name,
                'net' => $breakdown->net,
                'tax' => $breakdown->tax,
                'gross' => $breakdown->gross,
                'rate' => $ratePct,
                'currency' => $currency,
                'tax_rate_id' => $taxRate?->id,
                'billing_interval' => $billing->defaultBillingInterval,
                'billing_interval_count' => $billing->defaultBillingIntervalCount,
                'as_of' => $asOfDate,
            ],
            $display,
            $facts,
            entities: [
                EntityRef::unitClass($class, $site),
                EntityRef::site($site),
            ],
        );
    }
}
