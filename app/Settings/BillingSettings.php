<?php

namespace App\Settings;

readonly class BillingSettings implements SettingsPayload
{
    public function __construct(
        public string $defaultCurrency,
        public string $defaultBillingPeriod,
    ) {}

    public static function default(): static
    {
        return new self(
            defaultCurrency: '',
            defaultBillingPeriod: '',
        );
    }

    public static function fromArray(array $data): static
    {
        return new self(
            defaultCurrency: $data['default_currency'],
            defaultBillingPeriod: $data['default_billing_period'],
        );
    }

    public function toArray(): array
    {
        return [
            'default_currency'       => $this->defaultCurrency,
            'default_billing_period' => $this->defaultBillingPeriod,
        ];
    }

    public function with(
        ?string $defaultCurrency = null,
        ?string $defaultBillingPeriod = null,
    ): static {
        return new self(
            defaultCurrency: $defaultCurrency ?? $this->defaultCurrency,
            defaultBillingPeriod: $defaultBillingPeriod ?? $this->defaultBillingPeriod,
        );
    }
}
