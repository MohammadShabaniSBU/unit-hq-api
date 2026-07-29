<?php

declare(strict_types=1);

namespace App\Support\Communications\Contracts;

use App\Support\Communications\Channel;
use App\Support\Communications\Provider;
use App\Support\Communications\Results\VerificationResult;

interface ProviderAccount
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials): static;

    public function provider(): Provider;

    /** @return list<Channel> */
    public function channels(): array;

    /**
     * Form metadata for the settings UI.
     *
     * @return array<string, array{label: string, secret: bool}>
     */
    public function credentialFields(): array;

    public function verify(): VerificationResult;
}
