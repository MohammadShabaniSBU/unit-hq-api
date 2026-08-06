<?php

declare(strict_types=1);

namespace App\Support\Insights\Contracts;

/**
 * Declared in S21-02; minting implemented in S21-04.
 * InsightReport lands in S21-03 — type hint is forward-referenced.
 */
interface SignsEmbedTokens
{
    /**
     * @param  array<string, mixed>  $resolvedParams
     */
    public function embedUrl(\App\Models\InsightReport $report, array $resolvedParams): string;
}
