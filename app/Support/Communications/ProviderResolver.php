<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\CommunicationAccount;
use App\Models\Site;
use App\Support\Communications\Exceptions\ChannelNotConfigured;

/**
 * Resolves the active account for a channel and send class. Purposes are tried
 * in order (marketing: marketing → all; transactional: transactional → all);
 * within a purpose the site-scoped account wins over the company one.
 * A marketing send never falls back to a `transactional` account (M7), and a
 * transactional send never uses a `marketing` one.
 *
 * Archived sites keep credentials but must not send.
 */
final class ProviderResolver
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {}

    public function resolve(
        Channel $channel,
        ?Site $site = null,
        SendClass $class = SendClass::Transactional,
    ): ResolvedProvider {
        if ($site !== null && $site->isArchived()) {
            throw ChannelNotConfigured::siteArchived();
        }

        $account = null;

        foreach (AccountPurpose::eligibleFor($class) as $purpose) {
            if ($site !== null) {
                $account = CommunicationAccount::query()
                    ->where('scope', AccountScope::Site)
                    ->where('site_id', $site->id)
                    ->where('channel', $channel)
                    ->where('purpose', $purpose)
                    ->where('is_active', true)
                    ->first();
            }

            $account ??= CommunicationAccount::query()
                ->where('scope', AccountScope::Company)
                ->whereNull('site_id')
                ->where('channel', $channel)
                ->where('purpose', $purpose)
                ->where('is_active', true)
                ->first();

            if ($account !== null) {
                break;
            }
        }

        if ($account === null) {
            throw $class === SendClass::Marketing
                ? ChannelNotConfigured::forMarketing($channel)
                : ChannelNotConfigured::for($channel);
        }

        /** @var array<string, mixed> $credentials */
        $credentials = $account->credentials ?? [];

        $adapter = $this->registry->make($account->channel, $account->provider, $credentials);

        return new ResolvedProvider($account, $adapter);
    }
}
