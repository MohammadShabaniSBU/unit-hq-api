<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Deal;
use App\Models\Offer;
use App\Models\Price;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\OfferCreation;
use Illuminate\Support\Facades\DB;

final class SalesCreateOfferTool implements AgentTool, ProposableTool
{
    public function key(): string
    {
        return 'sales.create_offer';
    }

    public function description(): string
    {
        return 'Create a draft Offer with a public token after the prospect has agreed to a catalogue proposal from sales.propose_offer. Does not send the offer.';
    }

    public function schema(): array
    {
        return [
            'deal_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Deal id the offer belongs to',
            ],
            'options' => [
                'type' => 'array',
                'required' => true,
                'min' => 1,
                'max' => 4,
                'description' => '1–4 catalogue options',
                'items' => [
                    'site_id' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Site id the option is quoted at. Must match the deal site.',
                    ],
                    'unit_class_id' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Unit class id from facility.availability or sales.propose_offer',
                    ],
                    'quoted_price_id' => [
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Immutable prices.id from a prior pricing.quote or sales.propose_offer. Required when that class was quoted; refused if no longer current.',
                    ],
                    'quoted_tax_rate_id' => [
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'tax_rate_id from the same quote. Refused if tax resolution has moved on.',
                    ],
                    'discount_id' => [
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Catalogue discount id only',
                    ],
                    'label' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Label shown on the option. Defaults to the unit class label.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional longer description',
                    ],
                    'move_in_date' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'ISO date (YYYY-MM-DD). Written to the deal expected_move_in when the deal has none.',
                    ],
                ],
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Anonymous;
    }

    public function isWrite(): bool
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
            'deal_id' => EntityType::Deal,
            'discount_id' => EntityType::Discount,
            'site_id' => EntityType::Site,
            'unit_class_id' => EntityType::UnitClass,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        if ($ctx?->agent === null) {
            return ToolResult::error('Agent context is required.');
        }

        $proposed = $this->propose($principal, $arguments, $ctx);
        if ($proposed->status !== ToolInvocationStatus::Ok) {
            return $proposed;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($proposed->data['payload'] ?? null) ? $proposed->data['payload'] : [];

        return $this->commit(LeasingActor::agent($ctx->agent), $payload, $principal, $ctx);
    }

    public function propose(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $resolved = AllowlistedParent::resolve('deal', (int) $arguments['deal_id'], $principal);
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        /** @var Deal $deal */
        $deal = $resolved;
        if ($deal->site_id === null) {
            return ToolResult::error('Selected deal is missing a site and cannot create an offer.');
        }

        $site = Site::query()->find($deal->site_id);
        if ($site === null) {
            return ToolResult::error('Selected deal is missing a site and cannot create an offer.');
        }

        /** @var list<array<string, mixed>> $rawOptions */
        $rawOptions = is_array($arguments['options'] ?? null) ? $arguments['options'] : [];
        $payloadOptions = [];
        $previewLines = [];
        $moveInDate = null;
        $availabilityRecovery = [
            'tool' => 'facility.availability',
            'hint' => 'call facility.availability without a unit_class_id to list licensed classes',
        ];

        foreach ($rawOptions as $index => $raw) {
            if (! is_array($raw)) {
                return ToolResult::error('Each option must be an object.');
            }

            $optionSiteId = (int) ($raw['site_id'] ?? 0);
            if ($optionSiteId !== (int) $deal->site_id) {
                return ToolResult::fail(ToolError::invalidArguments(
                    'Option site_id does not match the deal site.',
                    ['hint' => "use the deal's site"],
                ));
            }

            $class = UnitClass::query()->find((int) ($raw['unit_class_id'] ?? 0));
            if ($class === null) {
                return ToolResult::notFound('Unit class not found.', recovery: $availabilityRecovery);
            }

            $rate = UnitClassRate::query()
                ->where('site_id', $optionSiteId)
                ->where('unit_class_id', $class->id)
                ->with(['price', 'unitClass'])
                ->first();
            if ($rate === null || $rate->price === null) {
                return ToolResult::notFound(
                    'No current catalogue price for that class at this site.',
                    recovery: $availabilityRecovery,
                );
            }

            $quotedPriceId = isset($raw['quoted_price_id']) ? (int) $raw['quoted_price_id'] : null;
            if ($quotedPriceId === null) {
                if (PriorCatalogueQuote::namesClass($ctx, $class->id)) {
                    return ToolResult::fail(ToolError::invalidArguments(
                        'quoted_price_id is required after a catalogue quote for this unit class.',
                        [
                            'tool' => 'pricing.quote',
                            'hint' => 'pass quoted_price_id from the quote',
                        ],
                    ));
                }
            } else {
                $quoted = Price::query()->find($quotedPriceId);
                if ($quoted === null) {
                    return ToolResult::notFound('Quoted price not found.');
                }
                if ($quoted->priceable_type !== 'unit_class_rate' || (int) $quoted->priceable_id !== (int) $rate->id) {
                    return ToolResult::fail(ToolError::invalidArguments(
                        'quoted_price_id does not belong to this unit class rate.',
                    ));
                }
                $current = $rate->price;
                if ($current === null || $current->id !== $quotedPriceId) {
                    return ToolResult::fail(ToolError::priceSuperseded(
                        'Catalogue price for this class has been superseded.',
                        [
                            'superseded' => 'price',
                            'quoted' => $quotedPriceId,
                            'current' => $current?->id,
                        ],
                    ));
                }
            }

            $discountId = isset($raw['discount_id']) ? (int) $raw['discount_id'] : null;
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

            if (array_key_exists('quoted_tax_rate_id', $raw) && $raw['quoted_tax_rate_id'] !== null) {
                $quotedTaxRateId = (int) $raw['quoted_tax_rate_id'];
                if ($line->taxRateId !== $quotedTaxRateId) {
                    return ToolResult::fail(ToolError::priceSuperseded(
                        'Tax rate for this class has been superseded.',
                        [
                            'superseded' => 'tax_rate',
                            'quoted' => $quotedTaxRateId,
                            'current' => $line->taxRateId,
                        ],
                    ));
                }
            }

            // Boolean availability gate only — the id is discarded. OfferCreation
            // pins a unit itself; inRandomOrder() means this id would almost
            // never match the one written on the option.
            if (Unit::resolveUnitIdForRate($rate->id) === null) {
                return ToolResult::notFound('No available unit found for the selected rate.');
            }

            $label = trim((string) ($raw['label'] ?? ''));
            if ($label === '') {
                $label = $class->label;
            }

            $option = [
                'unit_class_rate_id' => $rate->id,
                'label' => $label,
                'display_order' => $index,
            ];
            if ($discountId !== null) {
                $option['discount_id'] = $discountId;
            }
            $description = isset($raw['description']) ? trim((string) $raw['description']) : '';
            if ($description !== '') {
                $option['description'] = $description;
            }
            $payloadOptions[] = $option;

            if ($moveInDate === null) {
                $candidate = trim((string) ($raw['move_in_date'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
                    $moveInDate = $candidate;
                }
            }

            $previewLines[] = [
                'label' => $option['label'],
                'display' => $line->display,
                'net' => $line->net,
                'tax' => $line->tax,
                'gross' => $line->gross,
                'currency' => $line->currency,
                'rate' => $line->ratePct,
                'price_id' => $line->priceId,
                'tax_rate_id' => $line->taxRateId,
            ];
        }

        $deal->loadMissing('contact');
        $entities = [
            EntityRef::deal($deal),
            EntityRef::site($site),
        ];
        if ($deal->contact !== null) {
            $entities[] = EntityRef::contact($deal->contact);
        }

        return ToolResult::ok(
            [
                'payload' => [
                    'deal_id' => $deal->id,
                    'site_id' => $site->id,
                    'contact_id' => $deal->contact_id,
                    'options' => $payloadOptions,
                    'move_in_date' => $moveInDate,
                ],
                'preview' => [
                    'expires_at' => OfferCreation::defaultExpiry()->toIso8601String(),
                    'lines' => $previewLines,
                ],
            ],
            '',
            new FactBag,
            entities: $entities,
        );
    }

    public function commit(
        LeasingActor $actor,
        array $payload,
        AgentPrincipal $principal,
        ?AgentContext $ctx = null,
    ): ToolResult {
        $site = Site::query()->find((int) $payload['site_id']);
        if ($site === null) {
            return ToolResult::error('Selected deal is missing a site and cannot create an offer.');
        }

        /** @var list<array<string, mixed>> $payloadOptions */
        $payloadOptions = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        $createOptions = [];
        foreach ($payloadOptions as $option) {
            $row = [
                'unit_class_rate_id' => (int) $option['unit_class_rate_id'],
                'label' => (string) $option['label'],
                'display_order' => (int) $option['display_order'],
            ];
            if (isset($option['discount_id'])) {
                $row['discount_id'] = (int) $option['discount_id'];
            }
            if (isset($option['description'])) {
                $row['description'] = (string) $option['description'];
            }
            $createOptions[] = $row;
        }

        $offer = DB::transaction(function () use ($actor, $payload, $createOptions): Offer {
            $offer = OfferCreation::create(
                [
                    'deal_id' => (int) $payload['deal_id'],
                    'contact_id' => (int) $payload['contact_id'],
                    'expires_at' => OfferCreation::defaultExpiry(),
                ],
                $createOptions,
                [],
                $actor,
            );

            $moveIn = $payload['move_in_date'] ?? null;
            if (is_string($moveIn) && $moveIn !== '') {
                $deal = Deal::query()->find((int) $payload['deal_id']);
                if ($deal !== null && $deal->expected_move_in === null) {
                    $deal->expected_move_in = $moveIn;
                    $deal->save();
                }
            }

            return $offer;
        });

        $offer->load('options');

        return $this->committedResult($offer, $site, $principal, $payloadOptions, $ctx);
    }

    /**
     * @param  list<array<string, mixed>>  $payloadOptions
     */
    private function committedResult(
        Offer $offer,
        Site $site,
        AgentPrincipal $principal,
        array $payloadOptions,
        ?AgentContext $ctx,
    ): ToolResult {
        $facts = new FactBag;
        $displays = [];
        $optionData = [];

        foreach ($offer->options as $index => $created) {
            $raw = $payloadOptions[$index] ?? [];
            $rate = UnitClassRate::query()
                ->with(['price', 'unitClass'])
                ->find((int) $created->unit_class_rate_id);
            $class = $rate?->unitClass;
            if ($rate !== null && $class !== null) {
                $discountId = isset($raw['discount_id']) ? (int) $raw['discount_id'] : null;
                $registry = $ctx?->factRegistry;
                if ($registry === null) {
                    continue;
                }
                $line = CatalogueLinePricer::price($rate, $class, $site, $principal, $registry, $discountId);
                if (! $line instanceof ToolResult) {
                    $facts
                        ->money($line->net, $line->currency)
                        ->money($line->tax, $line->currency)
                        ->money($line->gross, $line->currency)
                        ->number($line->ratePct)
                        ->percent($line->ratePct);
                    $displays[] = $line->display;
                }
            }

            $optionData[] = [
                'id' => $created->id,
                'unit_class_rate_id' => $created->unit_class_rate_id,
                'unit_id' => $created->unit_id,
                'label' => $created->label,
            ];
        }

        $url = rtrim((string) config('app.panel_url'), '/').'/preview/offer/'.$offer->token;
        $expires = $offer->expires_at?->toDateString() ?? OfferCreation::defaultExpiry()->toDateString();
        $facts->date($expires)->identifier($url)->identifier($offer->token);

        $count = $offer->options->count();
        $optionWord = $count === 1 ? 'option' : 'options';
        $bits = ['Created a draft offer with '.$count.' '.$optionWord];
        if ($displays !== []) {
            $bits[] = implode('; ', $displays);
        }
        $display = implode('. ', $bits).". Expires {$expires}. Public link: {$url}. Nothing has been sent.";

        // absorb() licenses every DraftTokenExtractor token in this string
        // (e.g. :3000 in PANEL_URL). Safe only because $display is tool-authored
        // — never call absorb() on model output to silence a grounding failure.
        $facts->absorb($display);

        $entities = [
            EntityRef::offer($offer),
            EntityRef::site($site),
        ];
        foreach ($offer->options as $created) {
            if ($created->unit_id !== null) {
                $unit = Unit::query()->find($created->unit_id);
                if ($unit !== null) {
                    $entities[] = EntityRef::unit($unit, $site->name);
                }
            }
        }

        return ToolResult::ok(
            [
                'offer_id' => $offer->id,
                'url' => $url,
                'expires_at' => $expires,
                'options' => $optionData,
            ],
            $display,
            $facts,
            resultType: 'offer',
            resultId: $offer->id,
            entities: $entities,
        );
    }
}
