<?php

declare(strict_types=1);

namespace App\Support\Insights\Contracts;

use App\Support\Communications\Results\VerificationResult;

interface AnalyticsProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials, string $baseUrl): static;

    /**
     * Form metadata for the settings UI.
     *
     * @return array<string, array{label: string, secret: bool}>
     */
    public function credentialFields(): array;

    public function verify(): VerificationResult;

    /** @return list<string> */
    public function resourceKinds(): array;
}
