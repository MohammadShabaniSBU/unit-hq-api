<?php

namespace App\Http\Resources;

use App\Support\Playbooks\PlaybookEnrolmentSummary;
use Illuminate\Http\Request;

class DealResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'contact_id'             => $this->contact_id,
            'site_id'                => $this->site_id,
            'status'                 => $this->status,
            'expected_move_in'       => $this->date($this->expected_move_in),
            'expected_stay_length'   => $this->expected_stay_length,
            'expected_stay_period'   => $this->expected_stay_period,
            'desired_size'           => $this->desired_size,
            'desired_unit_class_id'  => $this->desired_unit_class_id,
            'created_at'             => $this->datetime($this->created_at),
            'updated_at'             => $this->datetime($this->updated_at),
            'active_playbook_enrolment' => ($this->additional['include_playbook_enrolment'] ?? false)
                ? PlaybookEnrolmentSummary::activeForSubject('deal', (int) $this->id)
                : null,
            'desired_unit_class'     => UnitClassResource::make($this->whenLoaded('desiredUnitClass')),
            'site'                   => $this->whenLoaded('site', fn () => [
                'id'   => $this->site->id,
                'name' => $this->site->name,
            ]),
            'contact'                => $this->whenLoaded('contact', fn () => [
                'id'    => $this->contact->id,
                'name'  => trim($this->contact->first_name . ' ' . $this->contact->last_name),
                'email' => $this->contact->email,
            ]),
            'offers'                 => OfferResource::collection($this->whenLoaded('offers')),
            'contracts'              => ContractResource::collection($this->whenLoaded('contracts')),
            'reservations'           => ReservationResource::collection($this->whenLoaded('reservations')),
            'tasks'                  => $this->whenLoaded('tasks', fn () =>
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
            'notes'                  => NoteResource::collection($this->whenLoaded('notes')),
        ];
    }
}
