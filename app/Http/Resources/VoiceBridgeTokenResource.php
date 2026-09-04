<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class VoiceBridgeTokenResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'site_id' => $this->site_id,
            'phone_number' => $this->phone_number,
            'main_line_number' => $this->main_line_number,
            'voicemail_number' => $this->voicemail_number,
            'label' => $this->label,
            'is_revoked' => $this->isRevoked(),
            'revoked_at' => $this->datetime($this->revoked_at),
            'created_at' => $this->datetime($this->created_at),
        ];
    }
}
