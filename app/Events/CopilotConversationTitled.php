<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CopilotConversationTitled implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public int $employeeId,
        public string $title,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("copilot-titles.{$this->employeeId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'copilot.conversation.titled';
    }

    /** @return array{conversation_id: string, title: string} */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'title' => $this->title,
        ];
    }
}
