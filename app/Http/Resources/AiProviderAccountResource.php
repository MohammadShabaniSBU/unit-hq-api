<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Ai\AiProviderRegistry;
use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\Request;

class AiProviderAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $registry = app(AiProviderRegistry::class);
        $providerValue = $this->provider->value;
        $fields = $registry->supports($providerValue)
            ? $registry->make($providerValue, [])->credentialFields()
            : [];

        $credentials = CredentialMasker::readSafely($this->resource, 'credentials');
        $credentials = is_array($credentials) ? $credentials : null;
        $unreadable = $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'credentials');

        return [
            'id' => $this->id,
            'provider' => $providerValue,
            'display_name' => $this->display_name,
            'credentials' => $unreadable ? [] : CredentialMasker::maskFields($credentials, $fields),
            'credentials_unreadable' => $unreadable,
            'allowed_models' => $this->allowed_models ?? [],
            'default_model' => $this->default_model,
            'is_default' => (bool) $this->is_default,
            'connection_status' => $this->connection_status->value,
            'last_error' => $this->last_error,
            'last_verified_at' => $this->datetime($this->last_verified_at),
            'archived_at' => $this->datetime($this->archived_at),
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
