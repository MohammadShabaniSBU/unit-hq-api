<?php

declare(strict_types=1);

namespace App\Support\Access;

/**
 * One desired grant triple produced by DesiredAccess (facts → desire).
 */
final readonly class DesiredGrant
{
    public function __construct(
        public int $contactId,
        public int $contractId,
        public int $accessPointId,
    ) {}
}
