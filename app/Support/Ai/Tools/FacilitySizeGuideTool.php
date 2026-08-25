<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

use App\Enums\SizeGuideMetric;
use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;

final class FacilitySizeGuideTool implements AgentTool
{
    public function key(): string
    {
        return 'facility.size_guide';
    }

    public function description(): string
    {
        return 'Look up the operator size guide for a quantity of goods (standard boxes, rooms, or a vehicle). Returns matching bands with a disclaimer. Never invent how much fits in a unit; ask what they are storing and call this tool.';
    }

    public function schema(): array
    {
        return [
            'metric' => [
                'type' => 'string',
                'required' => true,
                'enum' => array_map(
                    static fn (SizeGuideMetric $metric): string => $metric->value,
                    SizeGuideMetric::cases(),
                ),
                'description' => 'What the customer is counting: standard_boxes, room_equivalent, or vehicle',
            ],
            'quantity' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'How many boxes, rooms, or vehicles. Omit to list every band for the metric.',
            ],
            'site_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Limit resolution to one site plus company defaults',
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
            'site_id' => EntityType::Site,
        ];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        return ToolResult::notFound(
            'No size-guide band matched that metric and quantity. Ask what they are storing.',
        );
    }
}
