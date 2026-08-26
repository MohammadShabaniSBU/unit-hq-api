<?php

declare(strict_types=1);

namespace App\Support\Ai;

final readonly class ContextWindowResult
{
    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public array $messages,
        public int $messagesSent,
        public int $messagesEvicted,
        public int $summaryChars,
        public int $estimatedTokens,
    ) {}

    /**
     * @return array{messages_sent: int, messages_evicted: int, summary_chars: int, estimated_tokens: int}
     */
    public function telemetry(): array
    {
        return [
            'messages_sent' => $this->messagesSent,
            'messages_evicted' => $this->messagesEvicted,
            'summary_chars' => $this->summaryChars,
            'estimated_tokens' => $this->estimatedTokens,
        ];
    }
}
