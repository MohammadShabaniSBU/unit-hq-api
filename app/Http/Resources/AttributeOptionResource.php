<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AttributeOptionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'definition_id' => $this->definition_id,
            'label'         => $this->label,
            'display_order' => $this->display_order,
        ];
    }
}
