<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DelinquencyPolicyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'auto_release_overlock' => (bool) $this->auto_release_overlock,
            'sites_count' => $this->whenCounted('sites'),
            'steps' => $this->when(
                $this->relationLoaded('steps'),
                fn () => $this->steps->map(fn ($step) => [
                    'id' => $step->id,
                    'offset_days' => $step->offset_days,
                    'action' => $step->action?->value ?? $step->action,
                    'params' => $step->params ?? [],
                    'sort' => $step->sort,
                ])->values()->all()
            ),
            'archived_at' => $this->datetime($this->archived_at),
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
