<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Deal;
use App\Models\OfferOption;
use App\Models\Reservation;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ForbiddenClaimKey;
use App\Support\Ai\Enums\ToolInvocationStatus;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\ReservationCreation;
use App\Support\Time\SiteClock;
use Illuminate\Validation\ValidationException;

/**
 * Hold a unit in a class via auto-pick. ChannelAsserted, not Anonymous: a hold
 * requires a prospect arrived through a matched channel (email or phone that
 * exists). A fully anonymous webchat visitor gets a quote and an offer, not
 * inventory. unit_id and expires_at are never model arguments.
 */
final class SalesCreateReservationTool implements AgentTool, ProposableTool
{
    public function key(): string
    {
        return 'sales.create_reservation';
    }

    public function description(): string
    {
        return 'Put a hold on an available unit in a class via auto-pick after the prospect asks. Does not accept a unit id or expiry. Subject to colleague confirmation.';
    }

    public function schema(): array
    {
        return [
            'deal_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Deal id the hold belongs to',
            ],
            'unit_class_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Unit class id at the deal site',
            ],
            'offer_option_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Accepted offer option the hold follows, if any',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::ChannelAsserted;
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
            return ToolResult::error('Selected deal is missing a site and cannot create a reservation.');
        }

        $site = Site::query()->find($deal->site_id);
        if ($site === null) {
            return ToolResult::error('Selected deal is missing a site and cannot create a reservation.');
        }

        $unitClassId = (int) $arguments['unit_class_id'];
        $class = UnitClass::query()->find($unitClassId);
        $rate = UnitClassRate::query()
            ->with('price')
            ->where('site_id', $site->id)
            ->where('unit_class_id', $unitClassId)
            ->first();
        $price = $rate?->price;
        if ($class === null || $rate === null || $price === null) {
            return ToolResult::notFound('No current catalogue price for that class at this site.');
        }

        $offerOptionId = null;
        if (isset($arguments['offer_option_id'])) {
            $option = OfferOption::query()
                ->with(['offer', 'unitClassRate'])
                ->find((int) $arguments['offer_option_id']);
            if ($option === null || $option->offer === null) {
                return ToolResult::notFound('Offer option not found.');
            }
            if (
                (int) $option->offer->deal_id !== (int) $deal->id
                || (int) $option->offer->contact_id !== (int) $deal->contact_id
            ) {
                return ToolResult::error('Offer option does not belong to the selected deal.');
            }
            $optionClassId = $option->unitClassRate?->unit_class_id;
            if ($optionClassId === null || (int) $optionClassId !== $unitClassId) {
                return ToolResult::error('Offer option unit class does not match the requested class.');
            }
            $offerOptionId = $option->id;
        }

        if (ReservationCreation::agentHasActiveHold((int) $deal->contact_id, (int) $site->id, $unitClassId)) {
            return ToolResult::error('A hold is already in place for this class at this site.');
        }

        $available = Unit::query()
            ->where('site_id', $site->id)
            ->where('unit_class_id', $unitClassId)
            ->where('enabled', true)
            ->availableOn(SiteClock::today($site))
            ->count();

        if ($available === 0) {
            return ToolResult::notFound('No available unit found for the selected site and unit class.');
        }

        $payload = [
            'deal_id' => $deal->id,
            'site_id' => $site->id,
            'unit_class_id' => $class->id,
            'contact_id' => $deal->contact_id,
            'unit_class_rate_id' => $rate->id,
            'price_id' => $price->id,
        ];
        if ($offerOptionId !== null) {
            $payload['offer_option_id'] = $offerOptionId;
        }

        return ToolResult::ok(
            [
                'payload' => $payload,
                'preview' => [
                    'site_name' => $site->name,
                    'unit_class_label' => $class->label,
                    'expires_on' => ReservationCreation::defaultExpiry()->toDateString(),
                    'available_units' => $available,
                    'note' => 'Approving places a hold in this class, not on a specific unit.',
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
            return ToolResult::error('Selected deal is missing a site and cannot create a reservation.');
        }

        $offerOptionId = isset($payload['offer_option_id']) ? (int) $payload['offer_option_id'] : null;

        try {
            $reservation = ReservationCreation::create(
                (int) $payload['site_id'],
                (int) $payload['unit_class_id'],
                (int) $payload['contact_id'],
                (int) $payload['deal_id'],
                null,
                null,
                $offerOptionId,
                null,
                [],
                $actor,
            );
        } catch (ValidationException $exception) {
            return $this->mapCreationFailure($exception);
        }

        return $this->committedResult($reservation, $site);
    }

    private function committedResult(Reservation $reservation, Site $site): ToolResult
    {
        $reservation->loadMissing('unit.unitClass');
        $class = $reservation->unit?->unitClass;
        $classLabel = $class?->label ?? 'unit';
        $expiresOn = $reservation->expires_at?->toDateString()
            ?? ReservationCreation::defaultExpiry()->toDateString();

        $display = "Hold placed on a {$classLabel} unit at {$site->name}, expiring {$expiresOn}.";

        $facts = new FactBag;
        $facts->date($expiresOn);
        if ($class !== null) {
            // absorb() licenses the tokens DraftTokenExtractor actually emits
            // from a dimensional class label (e.g. Number("10") from "10 m²").
            // identifier() would not. Safe only because the label is catalogue
            // data, not model output.
            $facts->absorb($class->label);
        }
        // absorb() licenses every DraftTokenExtractor token in this string.
        // Safe only because $display is tool-authored — never call absorb() on
        // model output to silence a grounding failure.
        $facts->absorb($display);

        return ToolResult::ok(
            [
                'reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
                'expires_at' => $reservation->expires_at?->toIso8601String(),
                'expires_on' => $expiresOn,
            ],
            $display,
            $facts,
            resultType: 'reservation',
            resultId: $reservation->id,
            licensedClaims: [ForbiddenClaimKey::AvailabilityGuarantee],
        );
    }

    private function mapCreationFailure(ValidationException $exception): ToolResult
    {
        $message = collect($exception->errors())->flatten()->filter()->first();
        $message = is_string($message) && $message !== ''
            ? $message
            : 'Reservation could not be created.';

        if (
            str_contains($message, 'No available unit')
            || str_contains($message, 'No active price')
        ) {
            return ToolResult::notFound($message);
        }

        return ToolResult::error($message);
    }
}
