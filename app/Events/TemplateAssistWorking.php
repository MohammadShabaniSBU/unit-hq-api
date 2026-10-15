<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TemplateAssistWorking implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $sessionId,
        public int $turnId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("template-assist.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'template-assist.working';
    }

    /** @return array{turn_id: int} */
    public function broadcastWith(): array
    {
        return [
            'turn_id' => $this->turnId,
        ];
    }
}
