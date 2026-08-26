<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Discount;
use App\Models\Site;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Discounts\DiscountTerms;

final class PricingDiscountsTool implements AgentTool
{
    public function key(): string
    {
        return 'pricing.discounts';
    }

    public function description(): string
    {
        return 'List promotions the agent may offer as id, label, and customer-facing terms. Select an id; never invent a percentage.';
    }

    public function schema(): array
    {
        return [
            'site_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Site id (scopes the empty-catalogue message; catalogue is organisation-wide)',
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
        return false;
    }

    public function contactScopedArgumentKeys(): array
    {
        return [];
    }

    public function entityArguments(): array
    {
        return [
            'site_id' => EntityType::Site,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $site = Site::query()->find((int) $arguments['site_id']);
        if ($site === null) {
            return ToolResult::notFound('Site not found.');
        }

        $discounts = Discount::query()->active()->agentOfferable()->orderBy('name')->get();
        $rows = [];
        foreach ($discounts as $discount) {
            $terms = DiscountTerms::resolve($discount, $principal->locale, $site);
            if ($terms === null) {
                continue;
            }
            $rows[] = [
                'id' => $discount->id,
                'label' => $discount->name,
                'display' => $terms,
            ];
        }

        $facts = new FactBag;
        $entities = [];
        foreach ($rows as $row) {
            $facts->identifier((string) $row['id']);
            $facts->absorb((string) $row['display'], $site);
            $entities[] = EntityRef::of(
                EntityType::Discount,
                $row['id'],
                $row['label'],
            );
        }

        $display = $rows === []
            ? "No promotions are currently available at {$site->name}."
            : implode(' ', array_map(
                fn (array $row): string => "{$row['label']} (id {$row['id']}): {$row['display']}.",
                $rows,
            ));

        return ToolResult::ok(['discounts' => $rows], $display, $facts, entities: $entities);
    }
}
