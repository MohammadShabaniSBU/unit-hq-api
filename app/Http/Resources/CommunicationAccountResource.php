<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\Request;

class CommunicationAccountResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $key = CredentialMasker::readSafely($this->resource, 'api_key');

        return [
            'id' => $this->id,
            'scope' => $this->scope?->value,
            'site_id' => $this->site_id,
            'provider_type' => $this->provider_type?->value,
            'api_key_masked' => CredentialMasker::mask($key),
            'has_api_key' => $key !== null,
            'credentials_unreadable' => $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'api_key'),
            'webhook_configured' => $this->webhook_configured_at !== null,
            'webhook_configured_at' => $this->datetime($this->webhook_configured_at),
            'status' => $this->status?->value,
            'verified_at' => $this->datetime($this->verified_at),
            'last_error' => $this->last_error,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
