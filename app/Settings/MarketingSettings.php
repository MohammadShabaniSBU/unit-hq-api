<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\PlaybookKind;

/**
 * Marketing defaults. Stored here in S30, enforced by S31 at scheduling time.
 */
readonly class MarketingSettings implements SettingsPayload
{
    /**
     * @param  array<int, string>  $excludeActivePlaybookKinds  PlaybookKind values
     */
    public function __construct(
        public int $frequencyCapCount = 2,
        public int $frequencyCapDays = 7,
        public bool $excludeDelinquent = true,
        public array $excludeActivePlaybookKinds = ['debt_process'],
        public int $approvalThreshold = 500,
    ) {}

    public static function default(): static
    {
        return new self;
    }

    public static function fromArray(array $data): static
    {
        $default = self::default();

        $kinds = $data['marketing_exclude_active_playbook_kinds'] ?? $default->excludeActivePlaybookKinds;
        if (! is_array($kinds)) {
            $kinds = $default->excludeActivePlaybookKinds;
        }

        return new self(
            frequencyCapCount: (int) ($data['marketing_frequency_cap_count'] ?? $default->frequencyCapCount),
            frequencyCapDays: (int) ($data['marketing_frequency_cap_days'] ?? $default->frequencyCapDays),
            excludeDelinquent: (bool) ($data['marketing_exclude_delinquent'] ?? $default->excludeDelinquent),
            excludeActivePlaybookKinds: array_values(array_map('strval', $kinds)),
            approvalThreshold: (int) ($data['marketing_approval_threshold'] ?? $default->approvalThreshold),
        );
    }

    public function toArray(): array
    {
        return [
            'marketing_frequency_cap_count' => $this->frequencyCapCount,
            'marketing_frequency_cap_days' => $this->frequencyCapDays,
            'marketing_exclude_delinquent' => $this->excludeDelinquent,
            'marketing_exclude_active_playbook_kinds' => $this->excludeActivePlaybookKinds,
            'marketing_approval_threshold' => $this->approvalThreshold,
        ];
    }

    /**
     * @param  array<int, string>|null  $excludeActivePlaybookKinds
     */
    public function with(
        ?int $frequencyCapCount = null,
        ?int $frequencyCapDays = null,
        ?bool $excludeDelinquent = null,
        ?array $excludeActivePlaybookKinds = null,
        ?int $approvalThreshold = null,
    ): static {
        return new self(
            frequencyCapCount: $frequencyCapCount ?? $this->frequencyCapCount,
            frequencyCapDays: $frequencyCapDays ?? $this->frequencyCapDays,
            excludeDelinquent: $excludeDelinquent ?? $this->excludeDelinquent,
            excludeActivePlaybookKinds: $excludeActivePlaybookKinds ?? $this->excludeActivePlaybookKinds,
            approvalThreshold: $approvalThreshold ?? $this->approvalThreshold,
        );
    }

    /** @return array<int, string> */
    public static function playbookKindValues(): array
    {
        return array_map(static fn (PlaybookKind $kind): string => $kind->value, PlaybookKind::cases());
    }
}
