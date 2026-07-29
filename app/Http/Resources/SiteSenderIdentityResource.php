<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SiteSenderIdentityResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'channel' => $this->channel?->value,
            'account_id' => $this->account_id,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'from_number' => $this->from_number,
            'reply_to_email' => $this->reply_to_email,
            'provider_sender_id' => $this->provider_sender_id,
            'verified_at' => $this->datetime($this->verified_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
