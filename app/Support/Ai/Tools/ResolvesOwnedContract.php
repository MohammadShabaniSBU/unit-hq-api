<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\ToolDeniedReason;

trait ResolvesOwnedContract
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveOwnedContract(AgentPrincipal $principal, array $arguments): Contract|ToolResult
    {
        if ($principal->contactId === null) {
            return ToolResult::denied(
                ToolDeniedReason::Ownership,
                'This tool requires a contact principal.',
            );
        }

        $rawId = $arguments['contract_id'] ?? null;
        if ($rawId !== null && $rawId !== '') {
            $contractId = (int) $rawId;
            $exists = Contract::query()->whereKey($contractId)->exists();
            $owned = Contract::query()
                ->whereKey($contractId)
                ->where('contact_id', $principal->contactId)
                ->first();

            if ($owned === null) {
                return $exists
                    ? ToolResult::denied(
                        ToolDeniedReason::Ownership,
                        'Argument [contract_id] does not belong to this principal.',
                    )
                    : ToolResult::notFound('No contract found.');
            }

            return $owned;
        }

        $inForce = array_map(
            fn (ContractStatus $status): string => $status->value,
            array_values(array_filter(
                ContractStatus::cases(),
                fn (ContractStatus $status): bool => $status->isInForce(),
            )),
        );

        $contract = Contract::query()
            ->where('contact_id', $principal->contactId)
            ->whereIn('status', $inForce)
            ->orderByDesc('id')
            ->first();

        if ($contract === null) {
            return ToolResult::notFound('No active contract for this contact.');
        }

        return $contract;
    }
}
