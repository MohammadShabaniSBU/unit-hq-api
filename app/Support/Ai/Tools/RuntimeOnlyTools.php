<?php

declare(strict_types=1);

namespace App\Support\Ai\Tools;

/**
 * Tools registered in ToolRegistry that no AgentDefinition may claim.
 *
 * The runtime dispatches these as a terminal step; the model never sees them.
 * AgentToolCoverageTest exempts these keys from "appears in a definition"
 * and asserts the inverse: no definition lists them in toolKeys().
 */
final class RuntimeOnlyTools
{
    /** @var list<string> */
    public const KEYS = [
        'channel.send',
    ];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
