<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TemplateVariantResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_family_id' => $this->template_family_id,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'blocks' => $this->blocks,
            'legacy_html' => $this->legacy_html,
            'body_text' => $this->body_text,
            'updated_by' => $this->updated_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
