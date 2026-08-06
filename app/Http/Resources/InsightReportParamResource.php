<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class InsightReportParamResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'value_source' => $this->value_source->value,
            'static_value' => $this->static_value,
            'dynamic_key' => $this->dynamic_key,
            'binding' => $this->binding->value,
            'is_required' => (bool) $this->is_required,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
