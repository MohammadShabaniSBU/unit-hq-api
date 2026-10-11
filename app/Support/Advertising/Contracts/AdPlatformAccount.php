<?php

declare(strict_types=1);

namespace App\Support\Advertising\Contracts;

use App\Support\Advertising\Results\AccountInfo;

interface AdPlatformAccount
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function make(array $credentials): self;

    /**
     * @return array<string, array{label: string, secret: bool}>
     */
    public function credentialFields(): array;

    public function verify(): AccountInfo;
}
