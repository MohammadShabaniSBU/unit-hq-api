<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Full settings-surface representation (includes resource targeting + params).
 */
class InsightReportResource extends BaseResource
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
            'analytics_account_id' => $this->analytics_account_id,
            'resource_kind' => $this->resource_kind?->value,
            'resource_ref' => $this->resource_ref,
            'labels' => $this->labels,
            'description' => $this->description,
            'resolved_label' => $resolved,
            'icon' => $this->icon,
            'section' => $this->section,
            'sort_order' => (int) $this->sort_order,
            'visibility' => $this->visibility->value,
            'site_scope_mode' => $this->site_scope_mode->value,
            'options' => $this->options ?? [],
            'is_system' => (bool) $this->is_system,
            'archived_at' => $this->datetime($this->archived_at),
            'last_validated_at' => $this->datetime($this->last_validated_at),
            'validation_status' => $this->validation_status->value,
            'validation_detail' => $this->validation_detail,
            'created_by' => $this->created_by,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
            'params' => InsightReportParamResource::collection($this->whenLoaded('params')),
            'connection_status' => $this->when(
                $this->relationLoaded('analyticsAccount') && $this->analyticsAccount !== null,
                fn () => $this->analyticsAccount->connection_status->value,
            ),
        ];
    }
}
