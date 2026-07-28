<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier-3 audit logging shared by every credential lifecycle (create / rotate
 * / remove) — comms provider accounts and per-site Stripe settings.
 * Properties are limited to identifiers, the masked last-4, and the result;
 * the secret itself is never logged. See 09-conventions-and-invariants.md #27.
 */
final class CredentialAudit
{
    public static function created(string $entity, ?Model $subject, ?int $siteId, string $providerType, ?string $secret, string $result): void
    {
        self::record($entity, 'created', $subject, $siteId, $providerType, $secret, $result);
    }

    public static function rotated(string $entity, ?Model $subject, ?int $siteId, string $providerType, ?string $secret, string $result): void
    {
        self::record($entity, 'rotated', $subject, $siteId, $providerType, $secret, $result);
    }

    public static function removed(string $entity, ?Model $subject, ?int $siteId, string $providerType, ?string $secret): void
    {
        self::record($entity, 'removed', $subject, $siteId, $providerType, $secret, 'removed');
    }

    private static function record(
        string $entity,
        string $action,
        ?Model $subject,
        ?int $siteId,
        string $providerType,
        ?string $secret,
        string $result,
    ): void {
        RecordsActivity::core("{$entity}.{$action}", $subject, [
            'site_id' => $siteId,
            'provider_type' => $providerType,
            'masked' => CredentialMasker::mask($secret),
            'result' => $result,
        ]);
    }
}
