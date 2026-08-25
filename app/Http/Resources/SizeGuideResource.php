<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SizeGuideResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'site_name' => $this->whenLoaded('site', fn () => $this->site?->name),
            'unit_class_id' => $this->unit_class_id,
            'unit_class_label' => $this->whenLoaded('unitClass', fn () => $this->unitClass?->label),
            'metric' => $this->metric instanceof \BackedEnum ? $this->metric->value : $this->metric,
            'min_size' => $this->min_size,
            'max_size' => $this->max_size,
            'min_quantity' => $this->min_quantity,
            'max_quantity' => $this->max_quantity,
            'notes' => $this->notes,
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
