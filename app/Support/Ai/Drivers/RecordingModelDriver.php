<?php

declare(strict_types=1);

namespace App\Support\Ai\Drivers;

use Closure;

/**
 * Delegates to a live driver and keeps a copy of each ModelResponse for cassette writes.
 */
final class RecordingModelDriver implements ModelDriver
{
    /** @var list<ModelResponse> */
    public array $recorded = [];

    public int $callCount = 0;

    public function __construct(private readonly ModelDriver $inner) {}

    public function stream(array $messages, array $tools, string $model, ?Closure $onDelta): ModelResponse
    {
        $this->callCount++;
        $response = $this->inner->stream($messages, $tools, $model, $onDelta);
        $this->recorded[] = $response;

        return $response;
    }
}
