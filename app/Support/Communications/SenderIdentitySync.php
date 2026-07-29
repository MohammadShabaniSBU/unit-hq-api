<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\SiteSenderIdentity;

/**
 * When a site's active provider for a channel changes, Brevo's verified-sender
 * id means nothing to Postmark — null it so the new provider re-verifies.
 */
final class SenderIdentitySync
{
    public static function clearProviderSenderId(int $siteId, Channel $channel): void
    {
        SiteSenderIdentity::query()
            ->where('site_id', $siteId)
            ->where('channel', $channel)
            ->update([
                'provider_sender_id' => null,
                'verified_at' => null,
            ]);
    }

    public static function clearAllSitesForChannel(Channel $channel): void
    {
        SiteSenderIdentity::query()
            ->where('channel', $channel)
            ->update([
                'provider_sender_id' => null,
                'verified_at' => null,
            ]);
    }
}
