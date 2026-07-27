<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic polymorphic model lifecycle event for automation matching.
 * Causer is stamped at dispatch time (request lifecycle) — never re-resolved in queue workers.
 */
abstract class ModelLifecycleEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $dirty
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly int|string $subjectId,
        public readonly array $dirty,
        public readonly array $attributes,
        public readonly ?string $causerType,
        public readonly int|string|null $causerId,
    ) {}
}
