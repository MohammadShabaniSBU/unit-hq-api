<?php

declare(strict_types=1);

namespace App\Support\Ai\Summaries;

interface SummaryContext
{
    public function subjectLabel(): string;

    /**
     * Structured, redaction-safe arrays — not a prompt string.
     *
     * @return array<string, mixed>
     */
    public function build(): array;

    /** sha256 of the canonical serialized build() payload. */
    public function digest(): string;

    /**
     * Cheap staleness signal written onto the summary row.
     *
     * @return array<string, int>
     */
    public function counts(): array;

    /**
     * True when there is effectively nothing to summarize (identity-only).
     * POST must refuse with context_empty rather than burn a provider call.
     */
    public function isEmpty(): bool;
}
