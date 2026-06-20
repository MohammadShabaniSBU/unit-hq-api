<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SiteMapResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'site_id'    => $this->site_id,
            'floor_name' => $this->floor_name,
            'sort_order' => $this->sort_order,
            'svg_map'    => $this->when(
                array_key_exists('svg_map', $this->resource->getAttributes()),
                $this->svg_map
            ),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
