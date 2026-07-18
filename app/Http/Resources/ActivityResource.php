<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ActivityResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'event' => $this->event,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'properties' => $this->properties?->toArray() ?? [],
            'attribute_changes' => $this->attribute_changes?->toArray() ?? [],
            'created_at' => $this->datetime($this->created_at),
            'causer' => $this->whenLoaded('causer', function () {
                if ($this->causer === null) {
                    return null;
                }

                $name = $this->causer->name
                    ?? trim(($this->causer->first_name ?? '').' '.($this->causer->last_name ?? ''));

                return [
                    'id' => $this->causer->id,
                    'type' => $this->causer_type,
                    'name' => $name !== '' ? $name : null,
                ];
            }),
        ];
    }
}
