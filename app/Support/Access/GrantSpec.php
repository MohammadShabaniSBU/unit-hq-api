<?php

declare(strict_types=1);

namespace App\Support\Access;

/**
 * Spec for projecting a grant to a provider.
 *
 * @param  array{name: string, email?: string|null, phone?: string|null}  $person
 * @param  array<string, mixed>  $metadata
 */
final readonly class GrantSpec
{
    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $person
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $providerPointId,
        public array $person,
        public string $mode,
        public array $metadata = [],
    ) {}
}
