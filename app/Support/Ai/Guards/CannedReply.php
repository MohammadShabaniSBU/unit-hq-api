<?php

declare(strict_types=1);

namespace App\Support\Ai\Guards;

final class CannedReply
{
    public const Handoff = 'I am connecting you with a teammate who can help with this.';

    public const Budget = 'I have reached the limit for this conversation and am handing you to a teammate.';

    public const Error = 'Something went wrong. I am connecting you with a teammate.';

    public const Blocked = 'I need to hand this to a teammate.';

    public static function pendingApproval(string $locale): string
    {
        $lines = config('ai-handoff.pending_approval');
        if (! is_array($lines)) {
            return "I've asked a colleague to confirm that — you'll hear back shortly.";
        }

        $resolved = $lines[$locale] ?? $lines['en'] ?? null;

        return is_string($resolved)
            ? $resolved
            : "I've asked a colleague to confirm that — you'll hear back shortly.";
    }
}
