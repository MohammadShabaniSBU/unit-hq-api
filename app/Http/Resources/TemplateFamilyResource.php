<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class TemplateFamilyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel?->value ?? $this->channel,
            'name' => $this->name,
            'purpose' => $this->purpose?->value ?? $this->purpose,
            'archived_at' => $this->datetime($this->archived_at),
            'variants' => TemplateVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
