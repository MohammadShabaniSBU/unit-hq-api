<?php

declare(strict_types=1);

namespace App\Support\Ai\Trace;

use App\Support\Ai\AgentContext;
use App\Support\Ai\Enums\AgentMessageRole;

final class TraceCursor
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $turn,
        public readonly string $model,
        public readonly string $promptVersion,
        public readonly int $userMessageId,
        private int $nextSeq,
    ) {}

    public static function start(AgentContext $ctx, int $userMessageId): self
    {
        $conversationId = $ctx->conversation->id;
        $turn = (int) $ctx->conversation->messages()
            ->where('role', AgentMessageRole::User)
            ->count();

        return new self(
            $conversationId,
            $turn,
            $ctx->agent->model,
            $ctx->definition->promptVersion($ctx),
            $userMessageId,
            TraceSeq::max($conversationId),
        );
    }

    public function allocateSeq(): int
    {
        $this->nextSeq++;

        return $this->nextSeq;
    }

    /**
     * @return array{
     *     conversation_id: int,
     *     turn: int,
     *     seq: int,
     *     message_id: int|null,
     *     model: string,
     *     prompt_version: string,
     *     occurred_at: string
     * }
     */
    public function envelope(?int $messageId, int $seq, mixed $occurredAt = null): array
    {
        $at = $occurredAt instanceof \DateTimeInterface
            ? $occurredAt->format('Y-m-d H:i:s')
            : (is_string($occurredAt) && $occurredAt !== '' ? $occurredAt : now()->toDateTimeString());

        return [
            'conversation_id' => $this->conversationId,
            'turn' => $this->turn,
            'seq' => $seq,
            'message_id' => $messageId,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'occurred_at' => $at,
        ];
    }
}
