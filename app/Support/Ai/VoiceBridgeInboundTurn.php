<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Enums\VoiceBridgeProtocol;

/**
 * Protocol-neutral inbound Vocal Bridge turn. Wire-format fields are already
 * extracted; VoiceBridgeTurn never reads the raw request.
 */
final readonly class VoiceBridgeInboundTurn
{
    /**
     * @param  list<array{sequence: int, role: string, text: string, source: string, occurred_at?: string|null}>  $contextSegments
     */
    public function __construct(
        public VoiceBridgeProtocol $protocol,
        public ?string $query,
        public ?string $turnId,
        public ?string $sessionId,
        public ?string $callerNumber,
        public ?string $callerUtterance,
        public string|int|null $jsonRpcId,
        public array $contextSegments = [],
    ) {}
}
