<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Lean nav-feed item — no resource_ref, account id, or params.
 */
class InsightNavItemResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resolved = $this->resource->resolveLabel(app()->getLocale());

        return [
            'id' => $this->id,
            'key' => $this->key,
            'source' => $this->source->value,
            'native_key' => $this->native_key,
            'label' => $resolved['label'],
            'label_source' => $resolved['source'],
            'icon' => $this->icon,
            'section' => $this->section,
            'sort_order' => (int) $this->sort_order,
            'site_scope_mode' => $this->site_scope_mode->value,
            'options' => $this->options ?? [],
            'validation_status' => $this->validation_status->value,
            'connection_status' => $this->when(
                $this->source->value === 'embedded'
                    && $this->relationLoaded('analyticsAccount')
                    && $this->analyticsAccount !== null,
                fn () => $this->analyticsAccount->connection_status->value,
            ),
            'provider' => $this->when(
                $this->source->value === 'embedded'
                    && $this->relationLoaded('analyticsAccount')
                    && $this->analyticsAccount !== null,
                fn () => $this->analyticsAccount->provider->value,
            ),
        ];
    }
}
