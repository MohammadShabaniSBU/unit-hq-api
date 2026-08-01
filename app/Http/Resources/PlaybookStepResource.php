<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PlaybookStepResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'playbook_id' => $this->playbook_id,
            'offset_days' => $this->offset_days,
            'action' => $this->action,
            'params' => $this->params ?? [],
            'sort' => $this->sort,
            'created_at' => $this->datetime($this->created_at),
            'updated_at' => $this->datetime($this->updated_at),
        ];
    }
}
