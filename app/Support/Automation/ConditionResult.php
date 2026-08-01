<?php

declare(strict_types=1);

namespace App\Support\Automation;

/**
 * @phpstan-type Warning list<string>
 */
final readonly class ConditionResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public bool $passed,
        public array $warnings = [],
    ) {}

    public static function pass(array $warnings = []): self
    {
        return new self(true, $warnings);
    }

    /** @param  list<string>  $warnings */
    public static function fail(array $warnings = []): self
    {
        return new self(false, $warnings);
    }

    /** @param  list<string>  $extra */
    public function withWarnings(array $extra): self
    {
        if ($extra === []) {
            return $this;
        }

        return new self($this->passed, array_values(array_merge($this->warnings, $extra)));
    }
}
