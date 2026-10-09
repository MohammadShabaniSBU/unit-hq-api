<?php

declare(strict_types=1);

namespace App\Support\Marketing;

/**
 * File-level failure or a per-row import report.
 * created counts rows that were created, or would be created on a dry run.
 */
final class SubscriptionListImportResult
{
    /**
     * @param  list<array{
     *     line: int,
     *     status: string,
     *     reason: string|null,
     *     contact_id: int|null,
     *     channels: list<string>
     * }>  $rows
     */
    public function __construct(
        public ?string $errorKey = null,
        public bool $dryRun = true,
        public int $matched = 0,
        public int $created = 0,
        public int $rejected = 0,
        public array $rows = [],
    ) {}

    public function failed(): bool
    {
        return $this->errorKey !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'matched' => $this->matched,
            'created' => $this->created,
            'rejected' => $this->rejected,
            'rows' => $this->rows,
        ];
    }
}
