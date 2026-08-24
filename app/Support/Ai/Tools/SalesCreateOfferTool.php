<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Deal;
use App\Models\Offer;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\OfferCreation;

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
                    'unit_class_rate_id' => [
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'Unit class rate id at the deal site',
                    ],
                    'discount_id' => [
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Catalogue discount id only',
                    ],
                    'label' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Label shown on the option',
                    ],
                    'description' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional longer description',
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

        foreach ($rawOptions as $index => $raw) {
            if (! is_array($raw)) {
                return ToolResult::error('Each option must be an object.');
            }

            $rate = UnitClassRate::query()
                ->with(['price', 'unitClass'])
                ->find((int) $raw['unit_class_rate_id']);
            $class = $rate?->unitClass;
            if ($rate === null || $class === null) {
                return ToolResult::notFound('Unit class rate not found.');
            }

            if ((int) $rate->site_id !== (int) $deal->site_id) {
                return ToolResult::error('Unit class rate does not belong to the deal site.');
            }

            $discountId = isset($raw['discount_id']) ? (int) $raw['discount_id'] : null;
            $line = CatalogueLinePricer::price($rate, $class, $site, $principal, $discountId);
            if ($line instanceof ToolResult) {
                return $line;
            }

            // Boolean availability gate only — the id is discarded. OfferCreation
            // pins a unit itself; inRandomOrder() means this id would almost
            // never match the one written on the option.
            if (Unit::resolveUnitIdForRate($rate->id) === null) {
                return ToolResult::notFound('No available unit found for the selected rate.');
            }

            $option = [
                'unit_class_rate_id' => $rate->id,
                'label' => (string) $raw['label'],
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

            $previewLines[] = [
                'label' => $option['label'],
                'display' => $line->display,
                'net' => $line->net,
                'tax' => $line->tax,
                'gross' => $line->gross,
                'currency' => $line->currency,
                'rate' => $line->ratePct,
            ];
        }

        return ToolResult::ok(
            [
                'payload' => [
                    'deal_id' => $deal->id,
                    'site_id' => $site->id,
                    'contact_id' => $deal->contact_id,
                    'options' => $payloadOptions,
                ],
                'preview' => [
                    'expires_at' => OfferCreation::defaultExpiry()->toIso8601String(),
                    'lines' => $previewLines,
                ],
            ],
            '',
            new FactBag,
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

        $offer->load('options');

        return $this->committedResult($offer, $site, $principal, $payloadOptions);
    }

    /**
     * @param  list<array<string, mixed>>  $payloadOptions
     */
    private function committedResult(
        Offer $offer,
        Site $site,
        AgentPrincipal $principal,
        array $payloadOptions,
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
                $line = CatalogueLinePricer::price($rate, $class, $site, $principal, $discountId);
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
        $facts->date($expires)->identifier($url);

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
        );
    }
}
