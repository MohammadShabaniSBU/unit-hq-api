<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Models\Discount;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Guards\DraftTokenExtractor;
use App\Support\Discounts\DiscountSurface;

final class PricingDiscountsTool implements AgentTool
{
    public function key(): string
    {
        return 'pricing.discounts';
    }

    public function description(): string
    {
        return 'List active catalogue discounts as id, label, and display copy. Select an id; never invent a percentage.';
    }

    public function schema(): array
    {
        return [
            'site_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Site id (catalogue is organisation-wide)',
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

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        $discounts = Discount::query()->active()->orderBy('name')->get();
        $rows = [];
        foreach ($discounts as $discount) {
            $resolved = DiscountSurface::resolve($discount, locale: $principal->locale);
            $display = $resolved['promo_line'] ?? $discount->name;
            $rows[] = [
                'id' => $discount->id,
                'label' => $discount->name,
                'display' => $display,
            ];
        }

        $facts = new FactBag;
        $extractor = new DraftTokenExtractor;
        foreach ($rows as $row) {
            $facts->identifier((string) $row['id']);
            foreach ($extractor->extractPercents((string) $row['display']) as $percent) {
                $facts->percent($percent);
            }
        }

        $display = $rows === []
            ? 'No catalogue discounts are currently active.'
            : implode(' ', array_map(
                fn (array $row): string => "{$row['label']} (id {$row['id']}): {$row['display']}.",
                $rows,
            ));

        return ToolResult::ok(['discounts' => $rows], $display, $facts);
    }
}
