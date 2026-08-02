<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AccessPointType;
use App\Models\AccessPoint;
use Illuminate\Http\Request;

class AccessPointResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AccessPoint $point */
        $point = $this->resource;

        return [
            'id' => $point->id,
            'status' => $point->isArchived() ? 'archived' : 'assigned',
            'access_provider_account_id' => $point->access_provider_account_id,
            'provider_point_id' => $point->provider_point_id,
            'label' => $point->label,
            'point_type' => $point->point_type instanceof AccessPointType
                ? $point->point_type->value
                : (string) $point->point_type,
            'site_id' => $point->site_id,
            'site_name' => $point->relationLoaded('site') ? $point->site?->name : null,
            'unit_id' => $point->unit_id,
            'unit_number' => $point->relationLoaded('unit') ? $point->unit?->unit_number : null,
            'archived_at' => $this->datetime($point->archived_at),
            'created_at' => $this->datetime($point->created_at),
            'updated_at' => $this->datetime($point->updated_at),
        ];
    }
}
