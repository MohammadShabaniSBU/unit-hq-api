<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Access\AccessProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use App\Support\Http\PublicUrlGuard;
use Illuminate\Http\Request;

class AccessProviderAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $registry = app(AccessProviderRegistry::class);
        $adapter = $registry->supports($this->provider->value)
            ? $registry->make($this->provider->value, [])
            : null;
        $fields = $adapter?->credentialFields() ?? [];
        $modes = $adapter?->credentialModes() ?? [];

        $credentials = CredentialMasker::readSafely($this->resource, 'credentials');
        $credentials = is_array($credentials) ? $credentials : null;
        $unreadable = $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'credentials');

        $attributes = $this->resource->getAttributes();
        $webhookToken = is_string($attributes['webhook_token'] ?? null)
            ? $attributes['webhook_token']
            : null;

        $webhookUrl = $webhookToken !== null
            ? PublicUrlGuard::webhookUrl('api/webhooks/access/'.$webhookToken)
            : null;

        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'display_name' => $this->display_name,
            'credentials' => $unreadable ? [] : CredentialMasker::maskFields($credentials, $fields),
            'credentials_unreadable' => $unreadable,
            'credential_modes' => $modes,
            'webhook_url' => $webhookUrl,
            'webhook_state' => $this->webhook_state->value,
            'status' => $this->status->value,
            'last_error' => $this->last_error,
            'is_active' => (bool) $this->is_active,
            'discovered_points_count' => $this->resource->discoveredPointsCount(),
            'points_discovered_at' => $this->datetime($this->points_discovered_at),
            'last_full_synced_at' => $this->datetime($this->last_full_synced_at),
            'sync_attention' => $this->syncAttentionBlock(),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }

    /**
     * @return array{
     *     applied_count: int,
     *     failed_count: int,
     *     unknown_grants: list<array<string, mixed>>,
     *     drift_denied_but_granted: list<array<string, mixed>>
     * }
     */
    private function syncAttentionBlock(): array
    {
        $attention = is_array($this->resource->sync_attention)
            ? $this->resource->sync_attention
            : [];

        $unknown = is_array($attention['unknown_grants'] ?? null)
            ? array_values($attention['unknown_grants'])
            : [];
        $drift = is_array($attention['drift_denied_but_granted'] ?? null)
            ? array_values($attention['drift_denied_but_granted'])
            : [];

        return [
            'applied_count' => (int) ($attention['applied_count'] ?? 0),
            'failed_count' => (int) ($attention['failed_count'] ?? 0),
            'unknown_grants' => $unknown,
            'drift_denied_but_granted' => $drift,
        ];
    }
}
