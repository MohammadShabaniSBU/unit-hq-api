<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Support\Ai\AgentContext;
use App\Support\Ai\AgentPrincipal;
use App\Support\Ai\Enums\EntityType;
use App\Support\Ai\Enums\VerificationLevel;
use App\Support\Ai\Tools\AgentTool;
use App\Support\Ai\Tools\EntityRef;
use App\Support\Ai\Tools\FactBag;
use App\Support\Ai\Tools\ToolResult;

final class RefEmittingTool implements AgentTool
{
    public function key(): string
    {
        return 'test.refs';
    }

    public function description(): string
    {
        return 'Returns availability prose plus the entity refs behind it.';
    }

    public function schema(): array
    {
        return [];
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
        return [];
    }

    public function handle(AgentPrincipal $principal, array $arguments, ?AgentContext $ctx = null): ToolResult
    {
        return ToolResult::ok(
            ['site_id' => 1, 'unit_class_id' => 12, 'available' => 3],
            'Three units available in Trastero 16 m² XL at Madrid Centro as of now.',
            new FactBag,
            entities: [
                EntityRef::of(EntityType::UnitClass, 12, 'Trastero 16 m² XL'),
                EntityRef::of(EntityType::Site, 1, 'Madrid Centro'),
            ],
        );
    }
}
