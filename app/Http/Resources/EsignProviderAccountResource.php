<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Credentials\CredentialMasker;
use App\Support\ESign\ESignProviderRegistry;
use App\Support\Http\PublicUrlGuard;
use Illuminate\Http\Request;

class EsignProviderAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $registry = app(ESignProviderRegistry::class);
        $fields = $registry->supports($this->provider->value)
            ? $registry->make($this->provider->value, [])->credentialFields()
            : [];

        $credentials = CredentialMasker::readSafely($this->resource, 'credentials');
        $credentials = is_array($credentials) ? $credentials : null;
        $unreadable = $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'credentials');

        // webhook_token is $hidden — read from attributes for URL construction only.
        $attributes = $this->resource->getAttributes();
        $webhookToken = is_string($attributes['webhook_token'] ?? null)
            ? $attributes['webhook_token']
            : null;

        $webhookUrl = $webhookToken !== null
            ? PublicUrlGuard::webhookUrl('api/webhooks/esign/'.$webhookToken)
            : null;

        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'display_name' => $this->display_name,
            'credentials' => $unreadable ? [] : CredentialMasker::maskFields($credentials, $fields),
            'credentials_unreadable' => $unreadable,
            'webhook_url' => $webhookUrl,
            'webhook_state' => $this->webhook_state->value,
            'status' => $this->status->value,
            'last_error' => $this->last_error,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
