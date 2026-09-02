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
    public function __construct(
        public VoiceBridgeProtocol $protocol,
        public ?string $query,
        public ?string $turnId,
        public ?string $sessionId,
        public ?string $callerNumber,
        public string|int|null $jsonRpcId,
    ) {}
}
