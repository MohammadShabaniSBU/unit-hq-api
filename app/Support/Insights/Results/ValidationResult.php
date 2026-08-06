<?php

declare(strict_types=1);

namespace App\Support\Insights\Results;

use App\Enums\InsightValidationStatus;
use Illuminate\Support\Carbon;

final readonly class ValidationResult
{
    /**
     * @param  array<string, mixed>|null  $detail
     */
    public function __construct(
        public InsightValidationStatus $status,
        public ?array $detail,
        public Carbon $validatedAt,
    ) {}

    public function isValid(): bool
    {
        return $this->status === InsightValidationStatus::Valid;
    }

    public function isUnreachable(): bool
    {
        return $this->status === InsightValidationStatus::Unreachable;
    }

    /** Hard failure that blocks save (everything except valid / unreachable). */
    public function blocksSave(): bool
    {
        return ! $this->isValid() && ! $this->isUnreachable();
    }
}
