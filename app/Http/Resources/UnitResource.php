<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UnitResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'site_id'       => $this->site_id,
            'unit_class_id' => $this->unit_class_id,
            'unit_number'   => $this->unit_number,
            'actual_width'  => $this->actual_width,
            'actual_depth'  => $this->actual_depth,
            'actual_height' => $this->actual_height,
            'note'          => $this->note,
            'enabled'       => $this->enabled,
            'created_at'    => $this->datetime($this->created_at),
            'updated_at'    => $this->datetime($this->updated_at),
            'site'          => SiteResource::make($this->whenLoaded('site')),
            'unit_class'    => UnitClassResource::make($this->whenLoaded('unitClass')),
        ];
    }
}
