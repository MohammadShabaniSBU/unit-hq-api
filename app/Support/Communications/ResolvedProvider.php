<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Support\Communications\Exceptions\ChannelNotConfigured;
use App\Support\Communications\Exceptions\UnsupportedCapability;

/**
 * Pairs an account row with its adapter. require() asserts a capability
 * interface and throws UnsupportedCapability otherwise.
 */
final class ResolvedProvider
{
    public function __construct(
        public readonly CommunicationAccount $account,
        public readonly Contracts\ProviderAccount $adapter,
    ) {}

    /**
     * @template T of object
     *
     * @param  class-string<T>  $capability
     * @return T
     */
    public function require(string $capability, string $label): object
    {
        if (! $this->adapter instanceof $capability) {
            throw UnsupportedCapability::for($label);
        }

        /** @var T $adapter */
        $adapter = $this->adapter;

        return $adapter;
    }
}
