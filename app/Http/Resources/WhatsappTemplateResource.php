<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WhatsappTemplateResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'language' => $this->language,
            'category' => $this->category,
            'header_text' => $this->header_text,
            'body' => $this->body,
            'footer_text' => $this->footer_text,
            'buttons' => $this->buttons,
            'variables' => $this->variables,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'provider_template_id' => $this->provider_template_id,
            'submitted_at' => $this->datetime($this->submitted_at),
            'decided_at' => $this->datetime($this->decided_at),
            'communication_account_id' => $this->communication_account_id,
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
