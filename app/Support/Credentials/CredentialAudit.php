<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use App\Support\RecordsActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier-3 audit logging shared by every credential lifecycle (create / rotate
 * / remove) — comms provider accounts and payment provider accounts.
 * Properties are limited to identifiers, the masked last-4, and the result;
 * the secret itself is never logged. See 09-conventions-and-invariants.md #27.
 */
final class CredentialAudit
{
    public static function created(
        string $entity,
        ?Model $subject,
        ?int $siteId,
        string $provider,
        ?string $secret,
        string $result,
        ?string $channel = null,
    ): void {
        self::record($entity, 'created', $subject, $siteId, $provider, $secret, $result, $channel);
    }

    public static function rotated(
        string $entity,
        ?Model $subject,
        ?int $siteId,
        string $provider,
        ?string $secret,
        string $result,
        ?string $channel = null,
    ): void {
        self::record($entity, 'rotated', $subject, $siteId, $provider, $secret, $result, $channel);
    }

    public static function removed(
        string $entity,
        ?Model $subject,
        ?int $siteId,
        string $provider,
        ?string $secret,
        ?string $channel = null,
    ): void {
        self::record($entity, 'removed', $subject, $siteId, $provider, $secret, 'removed', $channel);
    }

    private static function record(
        string $entity,
        string $action,
        ?Model $subject,
        ?int $siteId,
        string $provider,
        ?string $secret,
        string $result,
        ?string $channel,
    ): void {
        $properties = [
            'site_id' => $siteId,
            'provider' => $provider,
            'masked' => CredentialMasker::mask($secret),
            'result' => $result,
        ];

        if ($channel !== null) {
            $properties['channel'] = $channel;
        }

        RecordsActivity::core("{$entity}.{$action}", $subject, $properties);
    }
}
