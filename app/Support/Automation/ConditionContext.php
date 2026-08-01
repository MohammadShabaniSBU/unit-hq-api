<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\ConditionSource;

/**
 * Explicit evaluation context for ConditionEvaluator.
 *
 * @see docs/automation-conditions.md
 */
final class ConditionContext
{
    /**
     * @param  array<string, mixed>  $oldValues  for `changed`
     * @param  array<string, string>|null  $fieldTypes  optional field => type overrides (tests / money keys)
     */
    public function __construct(
        public readonly ConditionSource $source,
        public readonly ?string $entityType = null,
        public readonly array $oldValues = [],
        public readonly string $timezone = 'UTC',
        public readonly ?array $fieldTypes = null,
    ) {}
}
