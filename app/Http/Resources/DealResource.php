<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\OfferResource;
use App\Http\Resources\LeaseResource;
use App\Http\Resources\ReservationResource;

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
            'storage_reason'         => $this->storage_reason,
            'desired_size'           => $this->desired_size,
            'desired_unit_class_id'  => $this->desired_unit_class_id,
            'intent_notes'           => $this->intent_notes,
            'created_at'             => $this->datetime($this->created_at),
            'updated_at'             => $this->datetime($this->updated_at),
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
            'leases'                 => LeaseResource::collection($this->whenLoaded('leases')),
            'reservations'           => ReservationResource::collection($this->whenLoaded('reservations')),
            'tasks'                  => $this->whenLoaded('tasks', fn () =>
                $this->tasks->map(fn ($task) => [
                    'id'          => $task->id,
                    'title'       => $task->title,
                    'description' => $task->description,
                    'priority'    => $task->priority,
                    'status'      => $task->status,
                    'due_date'    => $this->date($task->due_date),
                    'created_at'  => $this->datetime($task->created_at),
                ])
            ),
            'comments'               => $this->whenLoaded('comments', fn () =>
                $this->comments->map(fn ($comment) => [
                    'id'         => $comment->id,
                    'body'       => $comment->body,
                    'created_at' => $this->datetime($comment->created_at),
                ])
            ),
        ];
    }
}
