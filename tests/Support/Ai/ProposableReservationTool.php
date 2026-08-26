<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Models\Deal;
use App\Models\Site;
use App\Models\Unit;
use App\Models\UnitClass;
use App\Models\UnitClassRate;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ProposableTool;
use App\Support\Ai\Tools\ToolResult;
use App\Support\Leasing\LeasingActor;
use App\Support\Leasing\ReservationCreation;
use App\Support\Time\SiteClock;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ProposableReservationTool implements ProposableTool
{
    public bool $handleCalled = false;

    /** @var array<string, mixed>|null */
    public ?array $lastCommitPayload = null;

    /** @var (callable(): void)|null */
    public $beforeCommit = null;

    public function key(): string
    {
        return 'test.create_reservation';
    }

    public function description(): string
    {
        return 'Test proposable reservation tool.';
    }

    public function schema(): array
    {
        return [
            'deal_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Deal id',
            ],
            'unit_class_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Unit class id',
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

    public function retainInSummary(): bool
    {
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $this->handleCalled = true;

        throw new LogicException('ProposableReservationTool::handle() must not be called in propose mode.');
    }

    public function propose(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $deal = Deal::query()->find((int) $arguments['deal_id']);
        $class = UnitClass::query()->find((int) $arguments['unit_class_id']);
        if ($deal === null || $class === null) {
            return ToolResult::notFound('Deal or unit class not found.');
        }

        if ($deal->site_id === null) {
            return ToolResult::error('Selected deal is missing a site and cannot create a reservation.');
        }

        $site = Site::query()->find($deal->site_id);
        if ($site === null) {
            return ToolResult::error('Selected deal is missing a site and cannot create a reservation.');
        }

        $rate = UnitClassRate::query()
            ->with('price')
            ->where('site_id', $site->id)
            ->where('unit_class_id', $class->id)
            ->first();
        $price = $rate?->price;
        if ($rate === null || $price === null) {
            return ToolResult::notFound('No current catalogue price for that class at this site.');
        }

        $available = Unit::query()
            ->where('site_id', $site->id)
            ->where('unit_class_id', $class->id)
            ->where('enabled', true)
            ->availableOn(SiteClock::today($site))
            ->count();

        if ($available === 0) {
            return ToolResult::notFound('No available unit found for the selected site and unit class.');
        }

        return ToolResult::ok(
            [
                'payload' => [
                    'deal_id' => $deal->id,
                    'site_id' => $site->id,
                    'unit_class_id' => $class->id,
                    'contact_id' => $deal->contact_id,
                    'price_id' => $price->id,
                    'unit_class_rate_id' => $rate->id,
                ],
                'preview' => [
                    'unit_class_label' => $class->label,
                    'available_units' => $available,
                    'expires_at' => ReservationCreation::defaultExpiry()->toIso8601String(),
                    'amount' => (string) $price->amount,
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
        $this->lastCommitPayload = $payload;

        if ($this->beforeCommit !== null) {
            ($this->beforeCommit)();
        }

        try {
            $reservation = ReservationCreation::create(
                (int) $payload['site_id'],
                (int) $payload['unit_class_id'],
                (int) $payload['contact_id'],
                (int) $payload['deal_id'],
                null,
                null,
                null,
                null,
                [],
                $actor,
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->filter()->first();
            $message = is_string($message) && $message !== ''
                ? $message
                : 'Reservation could not be created.';

            if (str_contains($message, 'No available unit')) {
                return ToolResult::notFound($message);
            }

            return ToolResult::error($message);
        }

        return ToolResult::ok(
            ['reservation_id' => $reservation->id],
            'Reservation created.',
            new FactBag,
            resultType: 'reservation',
            resultId: $reservation->id,
        );
    }
}
