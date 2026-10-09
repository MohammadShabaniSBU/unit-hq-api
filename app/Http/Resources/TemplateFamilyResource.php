<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TemplateVersion;
use App\Support\Communications\TemplateFamilyUsage;
use Illuminate\Http\Request;

class TemplateFamilyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $this->currentVersion;
        $draft = $this->draft;
        $localesSource = $current ?? $draft;

        return [
            'id' => $this->id,
            'channel' => $this->channel?->value ?? $this->channel,
            'name' => $this->name,
            'purpose' => $this->purpose?->value ?? $this->purpose,
            'archived_at' => $this->datetime($this->archived_at),
            'locales' => $localesSource === null
                ? []
                : $localesSource->variants->pluck('locale')->values()->all(),
            'usage_count' => TemplateFamilyUsage::count($this->resource),
            'current_version' => $current instanceof TemplateVersion
                ? new TemplateVersionResource($current, 'current')
                : null,
            'draft_version' => $draft instanceof TemplateVersion
                ? new TemplateVersionResource($draft, 'draft')
                : null,
            'has_unpublished_changes' => $draft instanceof TemplateVersion,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
