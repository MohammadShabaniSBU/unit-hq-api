<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Interaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InteractionCreated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Interaction $interaction) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("contact.{$this->interaction->contact_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'interaction.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->interaction->id,
            'contact_id' => $this->interaction->contact_id,
            'deal_id' => $this->interaction->deal_id,
            'channel' => $this->interaction->channel,
            'direction' => $this->interaction->direction,
            'occurred_at' => $this->interaction->occurred_at?->toIso8601String(),
            'summary' => $this->interaction->summary,
            'content' => $this->interaction->content,
        ];
    }
}
