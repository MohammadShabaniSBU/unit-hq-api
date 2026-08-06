<?php

declare(strict_types=1);

namespace App\Support\Insights\Exceptions;

use RuntimeException;

/**
 * Fail-closed when a stored dynamic_key is not on the whitelist (I3).
 */
final class UnknownDynamicParamKey extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct('Unknown dynamic insight param key: '.$key);
    }
}
