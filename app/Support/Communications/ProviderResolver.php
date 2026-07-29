<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Support\Communications\Exceptions\ChannelNotConfigured;

/**
 * Resolves the active account for a channel:
 * 1. site-scoped active account (when a site is given)
 * 2. else company-scoped active account
 * 3. else ChannelNotConfigured
 *
 * Archived sites keep credentials but must not send.
 */
final class ProviderResolver
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {}

    public function resolve(Channel $channel, ?Site $site = null): ResolvedProvider
    {
        if ($site !== null && $site->isArchived()) {
            throw ChannelNotConfigured::siteArchived();
        }

        $account = null;

        if ($site !== null) {
            $account = CommunicationAccount::query()
                ->where('scope', AccountScope::Site)
                ->where('site_id', $site->id)
                ->where('channel', $channel)
                ->where('is_active', true)
                ->first();
        }

        $account ??= CommunicationAccount::query()
            ->where('scope', AccountScope::Company)
            ->whereNull('site_id')
            ->where('channel', $channel)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw ChannelNotConfigured::for($channel);
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $account->credentials ?? [];

        $adapter = $this->registry->make($account->channel, $account->provider, $credentials);

        return new ResolvedProvider($account, $adapter);
    }
}
