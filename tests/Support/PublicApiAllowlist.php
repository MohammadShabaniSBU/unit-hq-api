<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * URI patterns (Laravel route URI, no leading slash) that may omit Sanctum.
 * Keep in sync with the PUBLIC block in routes/api.php.
 *
 * Shared by RouteAuthCoverageTest and PermissionCoverageTest.
 */
final class PublicApiAllowlist
{
    /**
     * @var list<string>
     */
    public const URIS = [
        'api/login',
        'api/branding',
        'api/webhooks/stripe/{accountToken}',
        'api/webhooks/esign/{webhookToken}',
        'api/webhooks/access/{webhookToken}',
        'api/webhooks/{provider}/{webhookUrlToken}',
        'api/webhooks/{provider}/{webhookUrlToken}/inbound',
        'api/comms/unsubscribe/{token}',
        'api/public/template-assets/{hash}/{filename}',
        'api/offers/token/{token}',
        'api/offers/token/{token}/options/{offerOption}/map',
        'api/offer-options/{offerOption}/select',
        'api/pay/{token}',
        'api/pay/{token}/intent',
        'api/legal-entities/{legal_entity}/stripe/public-key',
        'api/invitations/{token}',
        'api/invitations/{token}/accept',
        'api/voice/bridge/{bridgeToken}',
        'api/voice/bridge/{bridgeToken}/config',
    ];

    public static function contains(string $uri): bool
    {
        return in_array($uri, self::URIS, true);
    }
}
