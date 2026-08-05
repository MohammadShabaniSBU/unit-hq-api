<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CopilotToolInvoking implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $toolName,
        public string $callId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("copilot.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'copilot.tool_invoking';
    }

    /** @return array{tool_name: string, call_id: string} */
    public function broadcastWith(): array
    {
        return [
            'tool_name' => $this->toolName,
            'call_id' => $this->callId,
        ];
    }
}
