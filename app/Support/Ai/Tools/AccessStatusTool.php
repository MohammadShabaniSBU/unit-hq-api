<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\HoldType;
use App\Models\Unit;
use App\Models\UnitHold;
use App\Support\Access\AccessState;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Time\SiteClock;

final class AccessStatusTool implements AgentTool
{
    use ResolvesOwnedContract;

    public function key(): string
    {
        return 'access.status';
    }

    public function description(): string
    {
        return 'Report whether access is active, suspended, or overlocked, with the reason category only. Never a gate code or credential.';
    }

    public function schema(): array
    {
        return [
            'contract_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Contract id; defaults to the principal\'s in-force contract',
            ],
        ];
    }

    public function requiredVerification(): VerificationLevel
    {
        return VerificationLevel::Verified;
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
        $contract = $this->resolveOwnedContract($principal, $arguments);
        if ($contract instanceof ToolResult) {
            return $contract;
        }

        $contract->loadMissing(['unitItem.item.site']);
        $unit = $contract->unitItem?->item;
        $site = $unit instanceof Unit ? $unit->site : null;
        $suspension = AccessState::suspensionBlock($contract);

        if ($suspension['active'] === true) {
            $reason = $suspension['reason'] ?? 'unknown';
            $display = $reason === 'delinquency'
                ? 'Access is currently suspended. A teammate will take this from here.'
                : 'Access is currently suspended.';

            return ToolResult::ok(
                [
                    'status' => 'suspended',
                    'reason' => $reason,
                ],
                $display,
                new FactBag,
            );
        }

        $overlocked = false;
        if ($unit instanceof Unit) {
            $today = $site !== null
                ? SiteClock::today($site)->toDateString()
                : now()->toDateString();
            $overlocked = UnitHold::query()
                ->where('unit_id', $unit->id)
                ->whereNull('released_at')
                ->where('hold_type', HoldType::Overlock->value)
                ->where('starts_on', '<=', $today)
                ->where(function ($q) use ($today): void {
                    $q->whereNull('ends_on')->orWhere('ends_on', '>', $today);
                })
                ->exists();
        }

        if ($overlocked) {
            return ToolResult::ok(
                [
                    'status' => 'overlocked',
                    'reason' => 'overlock',
                ],
                'Access is currently overlocked. A teammate will take this from here.',
                new FactBag,
            );
        }

        return ToolResult::ok(
            [
                'status' => 'active',
                'reason' => null,
            ],
            'Access is currently active.',
            new FactBag,
        );
    }
}
