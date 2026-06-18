<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ContactResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'first_name'           => $this->first_name,
            'last_name'            => $this->last_name,
            'email'                => $this->email,
            'company'              => $this->company,
            'status'               => $this->status,
            'contact_status'       => $this->contact_status,
            'canonical_contact_id' => $this->canonical_contact_id,
            'source'               => $this->source,
            'source_detail'        => $this->source_detail,
            'assigned_to'          => $this->assigned_to,
            'last_contacted_at'    => $this->datetime($this->last_contacted_at),
            'created_by'           => $this->created_by,
            'created_at'           => $this->datetime($this->created_at),
            'updated_at'           => $this->datetime($this->updated_at),
        ];
    }
}
