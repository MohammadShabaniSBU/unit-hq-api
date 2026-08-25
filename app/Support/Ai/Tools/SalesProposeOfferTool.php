<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Contact;
use App\Models\Setting;
use App\Models\Site;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\VerificationLevel;

final class SalesProposeOfferTool implements AgentTool
{
    public function key(): string
    {
        return 'sales.propose_offer';
    }

    public function description(): string
    {
        return 'Quote a catalogue proposal first (line items, tax, discount label, term). Persists nothing — no Offer row, no token, no send. Call sales.create_offer only after the prospect agrees.';
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

    public function entityArguments(): array
    {
        return [
            'contact_id' => EntityType::Contact,
            'site_id' => EntityType::Site,
            'unit_class_id' => EntityType::UnitClass,
            'discount_id' => EntityType::Discount,
        ];
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
        if ($rate === null) {
            return ToolResult::notFound('No current catalogue price for that class at this site.');
        }

        $discountId = isset($arguments['discount_id']) ? (int) $arguments['discount_id'] : null;
        $registry = $ctx?->factRegistry;
        if ($registry === null) {
            return ToolResult::denied(
                ToolDeniedReason::UnlicensedArgument,
                'FactRegistry is required.',
                ToolError::unlicensedArgument('FactRegistry is required.', [
                    'tool' => 'facility.availability',
                    'hint' => 'call facility.availability without a unit_class_id to list licensed classes',
                ]),
            );
        }
        $line = CatalogueLinePricer::price($rate, $class, $site, $principal, $registry, $discountId);
        if ($line instanceof ToolResult) {
            return $line;
        }

        $billing = Setting::billing();
        $count = $billing->defaultBillingIntervalCount;
        $interval = $billing->defaultBillingInterval;
        $term = $count === 1 ? "every {$interval}" : "every {$count} {$interval}s";

        $moveIn = isset($arguments['move_in_date']) ? (string) $arguments['move_in_date'] : null;

        $facts = (new FactBag)
            ->money($line->net, $line->currency)
            ->money($line->tax, $line->currency)
            ->money($line->gross, $line->currency)
            ->number($line->ratePct)
            ->percent($line->ratePct);
        if ($moveIn !== null && $moveIn !== '') {
            $facts->date($moveIn);
        }

        $bits = [
            "Proposal for {$class->label} at {$site->name}: {$line->display}, billed {$term}.",
        ];
        if ($line->discountLabel !== null) {
            $bits[] = "Catalogue discount: {$line->discountLabel}.";
        }
        if ($moveIn !== null && $moveIn !== '') {
            $bits[] = "Proposed move-in {$moveIn}.";
        }
        $bits[] = 'Nothing has been sent or saved.';

        $entities = [
            EntityRef::site($site),
            EntityRef::unitClass($class, $site),
        ];
        if ($line->discountId !== null && $line->discountLabel !== null) {
            $entities[] = EntityRef::of(
                EntityType::Discount,
                $line->discountId,
                $line->discountLabel,
            );
        }
        $contactId = isset($arguments['contact_id']) ? (int) $arguments['contact_id'] : null;
        if ($contactId !== null) {
            $contact = Contact::query()->find($contactId);
            if ($contact !== null) {
                $entities[] = EntityRef::contact($contact);
            }
        }

        return ToolResult::ok(
            [
                'site_id' => $site->id,
                'unit_class_id' => $class->id,
                'contact_id' => $contactId,
                'line_items' => [
                    [
                        'label' => $class->label,
                        'net' => $line->net,
                        'tax' => $line->tax,
                        'gross' => $line->gross,
                        'rate' => $line->ratePct,
                        'currency' => $line->currency,
                    ],
                ],
                'net' => $line->net,
                'tax' => $line->tax,
                'gross' => $line->gross,
                'currency' => $line->currency,
                'discount_id' => $line->discountId,
                'discount_label' => $line->discountLabel,
                'term' => $term,
                'move_in_date' => $moveIn,
                'persisted' => false,
            ],
            implode(' ', $bits),
            $facts,
            entities: $entities,
        );
    }
}
