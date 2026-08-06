<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\DealResource;
use App\Http\Resources\ContractResource;
use App\Http\Resources\ReservationResource;

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
            'billing_name'         => $this->billing_name,
            'tax_id'               => $this->tax_id,
            'tax_id_type'          => $this->tax_id_type?->value ?? $this->tax_id_type,
            'billing_address_line1'=> $this->billing_address_line1,
            'billing_address_line2'=> $this->billing_address_line2,
            'billing_city'         => $this->billing_city,
            'billing_postal_code'  => $this->billing_postal_code,
            'billing_country_code' => $this->billing_country_code,
            'locale'               => $this->locale,
            'fiscal_complete'      => $this->fiscalComplete(),
            'status'               => $this->status,
            'contact_status'       => $this->contact_status,
            'canonical_contact_id' => $this->canonical_contact_id,
            'assigned_to'          => $this->assigned_to,
            'last_contacted_at'    => $this->datetime($this->last_contacted_at),
            'created_by'           => $this->created_by,
            'created_at'           => $this->datetime($this->created_at),
            'updated_at'           => $this->datetime($this->updated_at),
            'channels'             => ContactChannelResource::collection($this->whenLoaded('channels')),
            'addresses'            => ContactAddressResource::collection($this->whenLoaded('addresses')),
            'deals'                => DealResource::collection($this->whenLoaded('deals')),
            'contracts'            => ContractResource::collection($this->whenLoaded('contracts')),
            'reservations'         => ReservationResource::collection($this->whenLoaded('reservations')),
            'tasks'                => $this->whenLoaded('tasks', fn () =>
                $this->tasks->map(fn ($task) => [
                    'id'          => $task->id,
                    'title'       => $task->title,
                    'description' => $task->description,
                    'priority'    => $task->priority,
                    'status'      => $task->status,
                    'type'        => $task->type?->value,
                    'due_date'    => $this->date($task->due_at),
                    'remind_at'   => $this->datetime($task->remind_at),
                    'created_at'  => $this->datetime($task->created_at),
                ])
            ),
            'notes'                => NoteResource::collection($this->whenLoaded('notes')),
        ];
    }
}
