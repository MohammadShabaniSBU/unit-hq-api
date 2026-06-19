<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\DealResource;
use App\Http\Resources\LeaseResource;
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
            'channels'             => $this->whenLoaded('channels', fn () =>
                $this->channels->map(fn ($ch) => [
                    'id'    => $ch->id,
                    'type'  => $ch->type,
                    'value' => $ch->value,
                ])
            ),
            'deals'                => DealResource::collection($this->whenLoaded('deals')),
            'leases'               => LeaseResource::collection($this->whenLoaded('leases')),
            'reservations'         => ReservationResource::collection($this->whenLoaded('reservations')),
            'tasks'                => $this->whenLoaded('tasks', fn () =>
                $this->tasks->map(fn ($task) => [
                    'id'          => $task->id,
                    'title'       => $task->title,
                    'description' => $task->description,
                    'priority'    => $task->priority,
                    'status'      => $task->status,
                    'due_date'    => $this->date($task->due_date),
                    'remind_at'   => $this->datetime($task->remind_at),
                    'created_at'  => $this->datetime($task->created_at),
                ])
            ),
            'comments'             => $this->whenLoaded('comments', fn () =>
                $this->comments->map(fn ($comment) => [
                    'id'         => $comment->id,
                    'body'       => $comment->body,
                    'created_at' => $this->datetime($comment->created_at),
                ])
            ),
        ];
    }
}
