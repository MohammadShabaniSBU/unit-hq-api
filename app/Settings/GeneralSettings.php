<?php

namespace App\Settings;

readonly class GeneralSettings implements SettingsPayload
{
    public function __construct(
        public string $companyName,
        public string $companyContactEmail,
        public string $phone,
        public string $sendWindowStart = '09:00',
    ) {}

    public static function default(): static
    {
        return new self(
            companyName: '',
            companyContactEmail: '',
            phone: '',
            sendWindowStart: '09:00',
        );
    }

    public static function fromArray(array $data): static
    {
        return new self(
            companyName: $data['company_name'],
            companyContactEmail: $data['company_contact_email'],
            phone: $data['phone'],
            sendWindowStart: is_string($data['send_window_start'] ?? null) && $data['send_window_start'] !== ''
                ? $data['send_window_start']
                : '09:00',
        );
    }

    public function toArray(): array
    {
        return [
            'company_name'          => $this->companyName,
            'company_contact_email' => $this->companyContactEmail,
            'phone'                 => $this->phone,
            'send_window_start'     => $this->sendWindowStart,
        ];
    }

    public function with(
        ?string $companyName = null,
        ?string $companyContactEmail = null,
        ?string $phone = null,
        ?string $sendWindowStart = null,
    ): static {
        return new self(
            companyName: $companyName ?? $this->companyName,
            companyContactEmail: $companyContactEmail ?? $this->companyContactEmail,
            phone: $phone ?? $this->phone,
            sendWindowStart: $sendWindowStart ?? $this->sendWindowStart,
        );
    }
}
