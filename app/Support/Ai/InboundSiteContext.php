<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\CommunicationAccount;
use App\Models\SiteSenderIdentity;
use App\Models\SystemEvent;
use App\Support\Ai\Enums\AgentChannel;
use App\Support\Communications\Channel;

final class InboundSiteContext
{
    public static function resolve(
        AgentChannel $channel,
        ?CommunicationAccount $account,
        ?string $destination = null,
    ): ?int {
        $commsChannel = self::commsChannel($channel);
        $destination = $destination !== null ? trim($destination) : null;
        if ($destination === '') {
            $destination = null;
        }

        $identity = null;
        if ($commsChannel !== null && $destination !== null) {
            $identity = self::identityForDestination($commsChannel, $destination);
        }

        $accountSiteId = $account?->site_id;

        if ($identity !== null) {
            if ($accountSiteId !== null && $accountSiteId !== $identity->site_id) {
                SystemEvent::record('ai.inbound.site_disagreement', $identity, [
                    'identity_site_id' => $identity->site_id,
                    'account_site_id' => $accountSiteId,
                    'account_id' => $account?->id,
                    'channel' => $channel->value,
                ]);
            }

            return $identity->site_id;
        }

        return $accountSiteId;
    }

    private static function commsChannel(AgentChannel $channel): ?Channel
    {
        return match ($channel) {
            AgentChannel::Email => Channel::Email,
            AgentChannel::Sms => Channel::Sms,
            AgentChannel::Whatsapp => Channel::Whatsapp,
            default => null,
        };
    }

    /**
     * Match the operator-owned inbound destination (the number/address the
     * customer contacted), never the customer's From.
     */
    private static function identityForDestination(Channel $channel, string $destination): ?SiteSenderIdentity
    {
        $normalized = mb_strtolower($destination);

        return SiteSenderIdentity::query()
            ->where('channel', $channel)
            ->where(function ($query) use ($destination, $normalized): void {
                $query
                    ->where('from_number', $destination)
                    ->orWhereRaw('lower(from_email) = ?', [$normalized]);
            })
            ->first();
    }
}
