<?php

declare(strict_types=1);

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
            'scene'      => $this->when(
                array_key_exists('scene', $this->resource->getAttributes()),
                $this->scene
            ),
            // Only present on create/update/validate responses — buckets of
            // SVG element ids vs. site units.unit_number (see SiteMapIdMatcher).
            'id_match'   => $this->when(isset($this->id_match), $this->id_match),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
