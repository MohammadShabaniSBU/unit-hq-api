<?php

declare(strict_types=1);

namespace App\Support\Ai\Contracts;

use App\Support\Ai\Results\AiVerificationResult;

interface AiProviderAdapter
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials): static;

    /**
     * Form metadata for the settings UI.
     *
     * @return array<string, array{label: string, secret: bool}>
     */
    public function credentialFields(): array;

    /**
     * Confirm the credentials work and discover the models this account can
     * currently use, live — never a hardcoded list, so the UI never goes stale.
     */
    public function verify(): AiVerificationResult;

    /**
     * Fallback model IDs used only when live discovery fails, so setup isn't
     * blocked by a transient provider outage.
     *
     * @return list<string>
     */
    public function fallbackModels(): array;
}
