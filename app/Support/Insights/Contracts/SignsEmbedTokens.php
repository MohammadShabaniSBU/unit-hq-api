<?php

declare(strict_types=1);

namespace App\Support\Insights\Contracts;

use App\Models\InsightReport;

/**
 * Declared in S21-02; minting implemented in S21-04.
 */
interface SignsEmbedTokens
{
    /**
     * @param  array<string, mixed>  $resolvedParams
     */
    public function embedUrl(InsightReport $report, array $resolvedParams): string;
}
