<?php

declare(strict_types=1);

namespace App\Support\Access;

/**
 * Seam for afterCommit sync nudges from fact writers (suspension, later occupancy/overlock).
 * S15-02 wires SyncAccess job dispatch here.
 */
final class AccessSync
{
    public static function nudge(int $contractId): void
    {
        // Wired in S15-02.
    }
}
