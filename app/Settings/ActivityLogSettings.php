<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\LogChannel;

readonly class ActivityLogSettings implements SettingsPayload
{
    /**
     * @param  array<int, string>  $channels  Enabled tier-2 channel values
     */
    public function __construct(
        public array $channels,
        public int $retentionMonths,
    ) {}

    public static function default(): static
    {
        return new self(
            channels: LogChannel::optionalValues(),
            retentionMonths: 12,
        );
    }

    public static function fromArray(array $data): static
    {
        $channels = $data['channels'] ?? LogChannel::optionalValues();
        if (! is_array($channels)) {
            $channels = LogChannel::optionalValues();
        }

        return new self(
            channels: array_values(array_map('strval', $channels)),
            retentionMonths: (int) ($data['retention_months'] ?? 12),
        );
    }

    public function toArray(): array
    {
        return [
            'channels' => $this->channels,
            'retention_months' => $this->retentionMonths,
        ];
    }

    /**
     * @param  array<int, string>|null  $channels
     */
    public function with(?array $channels = null, ?int $retentionMonths = null): static
    {
        return new self(
            channels: $channels ?? $this->channels,
            retentionMonths: $retentionMonths ?? $this->retentionMonths,
        );
    }

    public function isChannelEnabled(string $channel): bool
    {
        if ($channel === LogChannel::Core->value) {
            return true;
        }

        return in_array($channel, $this->channels, true);
    }
}
