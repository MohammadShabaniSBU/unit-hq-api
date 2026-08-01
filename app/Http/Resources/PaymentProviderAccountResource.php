<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\Request;

class PaymentProviderAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $secretKey = CredentialMasker::readSafely($this->resource, 'secret_key');

        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'provider' => $this->provider,
            'display_name' => $this->display_name,
            'publishable_key' => $this->publishable_key,
            'secret_key_masked' => CredentialMasker::mask($secretKey),
            'has_secret_key' => $secretKey !== null,
            'credentials_unreadable' => $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'secret_key'),
            'webhook_configured' => $this->webhook_endpoint_id !== null,
            'provider_account_id' => $this->provider_account_id,
            'provider_account_mismatch' => (bool) ($this->resource->providerAccountMismatch ?? false),
            'status' => $this->status?->value,
            'last_error' => $this->last_error,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
