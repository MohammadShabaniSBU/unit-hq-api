<?php

declare(strict_types=1);

namespace App\Support\Templates;

use App\Enums\TemplatePurpose;

final readonly class TokenDefinition
{
    /**
     * @param  list<TemplatePurpose>  $purposes
     */
    public function __construct(
        public string $path,
        public TokenGroup $group,
        public TokenType $type,
        public string $labelKey,
        public string $descriptionKey,
        public string $sample,
        public array $purposes,
    ) {}

    public function availableFor(TemplatePurpose $purpose): bool
    {
        return in_array($purpose, $this->purposes, true);
    }
}
