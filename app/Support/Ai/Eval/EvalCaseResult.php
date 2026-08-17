<?php

declare(strict_types=1);

namespace App\Support\Ai\Eval;

final class EvalCaseResult
{
    /**
     * @param  list<string>  $failures
     */
    public function __construct(
        public string $id,
        public string $agent,
        public bool $passed,
        public array $failures,
        public int $toolCalls,
        public int $tokens,
        public bool $blockedUnexpectedly,
        public bool $liveOnly,
        public ?string $draft,
        public ?int $smsSegments = null,
    ) {}
}
