<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

use App\Models\UnitClassRate;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\ToolDeniedReason;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactRegistry;
use App\Support\Ai\Tools\ToolDispatchState;
use App\Support\Ai\Tools\ToolError;
use App\Support\Ai\Tools\ToolResult;

final class ArgumentProvenance
{
    /** @var array<string, string> */
    private const RECOVERY_TOOL = [
        'site' => 'facility.find_sites',
        'unit_class' => 'facility.availability',
        'discount' => 'pricing.discounts',
        'contact' => 'crm.create_contact',
        'deal' => 'crm.create_deal',
        'offer' => 'sales.propose_offer',
        'contract' => 'contract.summary',
        'invoice' => 'billing.invoices',
        'reservation' => 'sales.create_reservation',
    ];

    /** @var array<string, string> */
    private const RECOVERY_HINT = [
        'site' => 'call facility.find_sites with a city or postcode',
        'unit_class' => 'call facility.availability without a unit_class_id to list licensed classes',
        'discount' => 'call pricing.discounts for catalogue ids',
        'contact' => 'call crm.create_contact',
        'deal' => 'call crm.create_deal',
        'offer' => 'call sales.propose_offer',
        'contract' => 'omit contract_id and use contract.summary',
        'invoice' => 'call billing.invoices',
        'reservation' => 'call sales.create_reservation',
    ];

    private ?FactRegistry $memo = null;

    private ?int $memoConversationId = null;

    public function resetMemo(): void
    {
        $this->memo = null;
        $this->memoConversationId = null;
    }

    /**
     * @param  list<EntityRef>  $entities
     */
    public function absorb(array $entities): void
    {
        if ($this->memo === null || $entities === []) {
            return;
        }

        $this->memo->absorb(...$entities);
    }

    public function denyIfUnlicensed(ToolDispatchState $state): ?ToolResult
    {
        $registry = $this->registryFor($state);
        $state->factRegistry = $registry;

        $tool = $state->tool();
        $denied = $this->checkBag($state->arguments, $tool->entityArguments(), $registry, '');
        if ($denied !== null) {
            return $denied;
        }

        return $this->checkRateIds($state->arguments, $registry);
    }

    private function registryFor(ToolDispatchState $state): FactRegistry
    {
        $conversationId = $state->ctx?->conversation->id;
        if ($conversationId === null) {
            return FactRegistry::rebuild($state->principal, null);
        }

        if ($this->memo !== null && $this->memoConversationId === $conversationId) {
            return $this->memo;
        }

        $this->memo = FactRegistry::rebuild($state->principal, $state->ctx);
        $this->memoConversationId = $conversationId;

        return $this->memo;
    }

    /**
     * @param  array<string, mixed>  $bag
     * @param  array<string, EntityType|string>  $entityArgs
     */
    private function checkBag(array $bag, array $entityArgs, FactRegistry $registry, string $prefix): ?ToolResult
    {
        foreach ($entityArgs as $key => $typeOrPointer) {
            $value = $this->presentInt($bag, $key);
            if ($value === null) {
                continue;
            }

            $type = $this->resolveType($key, $typeOrPointer, $bag);
            if ($type instanceof ToolResult) {
                return $type;
            }

            if (! $registry->contains($type, $value)) {
                return $this->deny($prefix.$key, $value, $type, $registry);
            }
        }

        foreach ($bag as $item) {
            if (! is_array($item) || array_is_list($item)) {
                continue;
            }

            $nested = $this->checkBag($item, $entityArgs, $registry, $prefix);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function checkRateIds(array $bag, FactRegistry $registry): ?ToolResult
    {
        $top = $this->presentInt($bag, 'unit_class_rate_id');
        if ($top !== null) {
            $denied = $this->denyUnlicensedRate($top, $registry);
            if ($denied !== null) {
                return $denied;
            }
        }

        foreach ($bag as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (array_is_list($item)) {
                foreach ($item as $row) {
                    if (! is_array($row) || array_is_list($row)) {
                        continue;
                    }
                    $denied = $this->checkRateIds($row, $registry);
                    if ($denied !== null) {
                        return $denied;
                    }
                }

                continue;
            }

            $denied = $this->checkRateIds($item, $registry);
            if ($denied !== null) {
                return $denied;
            }
        }

        return null;
    }

    private function denyUnlicensedRate(int $rateId, FactRegistry $registry): ?ToolResult
    {
        $rate = UnitClassRate::query()->find($rateId);
        $classId = $rate !== null ? (int) $rate->unit_class_id : 0;
        if ($classId > 0 && $registry->contains(EntityType::UnitClass, $classId)) {
            return null;
        }

        return $this->deny('unit_class_rate_id', $rateId, EntityType::UnitClass, $registry);
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function resolveType(string $key, EntityType|string $typeOrPointer, array $bag): EntityType|ToolResult
    {
        if ($typeOrPointer instanceof EntityType) {
            return $typeOrPointer;
        }

        $alias = $bag[$typeOrPointer] ?? null;
        if (! is_string($alias) || $alias === '') {
            return ToolResult::fail(ToolError::invalidArguments(
                "Argument [{$key}] is missing a type from [{$typeOrPointer}].",
            ));
        }

        $type = EntityType::tryFrom($alias);
        if ($type === null) {
            return ToolResult::fail(ToolError::invalidArguments(
                "Argument [{$key}] type [{$alias}] is not a licensed entity type.",
            ));
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function presentInt(array $bag, string $key): ?int
    {
        if (! array_key_exists($key, $bag)) {
            return null;
        }

        $value = $bag[$key];
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function deny(string $argument, mixed $value, EntityType $type, FactRegistry $registry): ToolResult
    {
        $detail = [
            'argument' => $argument,
            'value' => $value,
            'type' => $type->value,
            'licensed' => $registry->ids($type),
        ];
        $message = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $type->value;

        $recovery = null;
        $tool = self::RECOVERY_TOOL[$type->value] ?? null;
        if ($tool !== null) {
            $recovery = [
                'tool' => $tool,
                'hint' => self::RECOVERY_HINT[$type->value] ?? 'call '.$tool,
            ];
        }

        return ToolResult::denied(
            ToolDeniedReason::UnlicensedArgument,
            $message,
            ToolError::unlicensedArgument($message, $recovery),
        );
    }
}
