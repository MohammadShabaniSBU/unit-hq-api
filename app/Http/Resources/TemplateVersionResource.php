<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TemplateVersionStatus;
use Illuminate\Http\Request;

class TemplateVersionResource extends BaseResource
{
    public function __construct(
        mixed $resource,
        private readonly string $shape = 'current',
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variants = TemplateVariantResource::collection($this->variants);

        if ($this->shape === 'draft') {
            return [
                'id' => $this->id,
                'version_number' => $this->version_number,
                'based_on_version_id' => $this->based_on_version_id,
                'variants' => $variants,
            ];
        }

        if ($this->shape === 'current') {
            return [
                'id' => $this->id,
                'version_number' => $this->version_number,
                'published_at' => $this->datetime($this->published_at),
                'published_by' => $this->published_by,
                'variants' => $variants,
            ];
        }

        $status = $this->status instanceof TemplateVersionStatus
            ? $this->status->value
            : $this->status;

        $history = [
            'id' => $this->id,
            'version_number' => $this->version_number,
            'status' => $status,
            'published_at' => $this->datetime($this->published_at),
            'published_by' => $this->published_by,
            'based_on_version_id' => $this->based_on_version_id,
            'locales' => $this->variants->pluck('locale')->values()->all(),
            // S29-02 fills this from per-version sends. No send rows exist yet.
            'usage_count' => 0,
        ];

        if ($this->shape === 'detail') {
            $history['variants'] = $variants;
        }

        return $history;
    }
}
