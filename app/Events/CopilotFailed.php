<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CopilotFailed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $conversationId,
        public string $callId,
        public string $errorKey = 'copilot.stream.failed',
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
        return 'copilot.failed';
    }

    /** @return array{error_key: string, call_id: string, conversation_id: string} */
    public function broadcastWith(): array
    {
        return [
            'error_key' => $this->errorKey,
            'call_id' => $this->callId,
            'conversation_id' => $this->conversationId,
        ];
    }
}
