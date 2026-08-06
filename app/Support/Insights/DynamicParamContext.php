<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Models\Employee;
use App\Models\Site;

/**
 * Request context for resolving DynamicParams whitelist keys at mint time.
 */
final class DynamicParamContext
{
    public function __construct(
        public readonly Employee $employee,
        public readonly ?int $siteId,
        public readonly ?Site $site,
        public readonly string $locale,
        public readonly bool $applySiteScope,
    ) {}
}
