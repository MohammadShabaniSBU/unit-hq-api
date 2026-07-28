<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Credentials\CredentialMasker;
use Illuminate\Http\Request;

class SiteStripeSettingResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $secretKey = CredentialMasker::readSafely($this->resource, 'secret_key');

        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'publishable_key' => $this->publishable_key,
            'secret_key_masked' => CredentialMasker::mask($secretKey),
            'has_secret_key' => $secretKey !== null,
            'credentials_unreadable' => $this->id !== null && CredentialMasker::isUnreadable($this->resource, 'secret_key'),
            'webhook_configured' => $this->webhook_endpoint_id !== null,
            'status' => $this->status?->value,
            'verified_at' => $this->datetime($this->verified_at),
            'last_error' => $this->last_error,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
