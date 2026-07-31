<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UnitOccupancyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $contract = $this->relationLoaded('contract') ? $this->contract : null;
        $contact = $contract?->relationLoaded('contact') ? $contract->contact : null;

        return [
            'id'            => $this->id,
            'unit_id'       => $this->unit_id,
            'contract_id'   => $this->contract_id,
            'started_on'    => $this->date($this->started_on),
            'ended_on'      => $this->date($this->ended_on),
            'ended_reason'  => $this->ended_reason,
            'tenant_name'   => $contact !== null
                ? trim($contact->first_name.' '.$contact->last_name)
                : null,
            'created_by'    => $this->created_by,
            'created_at'    => $this->datetime($this->created_at),
            'updated_at'    => $this->datetime($this->updated_at),
        ];
    }
}
