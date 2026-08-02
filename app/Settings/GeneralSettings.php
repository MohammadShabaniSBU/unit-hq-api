<?php

namespace App\Settings;

readonly class GeneralSettings implements SettingsPayload
{
    public function __construct(
        public string $companyName,
        public string $companyContactEmail,
        public string $phone,
        public string $sendWindowStart = '09:00',
        public string $emailAccentColor = '#1d4ed8',
    ) {}

    public static function default(): static
    {
        return new self(
            companyName: '',
            companyContactEmail: '',
            phone: '',
            sendWindowStart: '09:00',
            emailAccentColor: '#1d4ed8',
        );
    }

    public static function fromArray(array $data): static
    {
        $accent = is_string($data['email_accent_color'] ?? null) && $data['email_accent_color'] !== ''
            ? $data['email_accent_color']
            : '#1d4ed8';

        return new self(
            companyName: $data['company_name'],
            companyContactEmail: $data['company_contact_email'],
            phone: $data['phone'],
            sendWindowStart: is_string($data['send_window_start'] ?? null) && $data['send_window_start'] !== ''
                ? $data['send_window_start']
                : '09:00',
            emailAccentColor: $accent,
        );
    }

    public function toArray(): array
    {
        return [
            'company_name'          => $this->companyName,
            'company_contact_email' => $this->companyContactEmail,
            'phone'                 => $this->phone,
            'send_window_start'     => $this->sendWindowStart,
            'email_accent_color'    => $this->emailAccentColor,
        ];
    }

    public function with(
        ?string $companyName = null,
        ?string $companyContactEmail = null,
        ?string $phone = null,
        ?string $sendWindowStart = null,
        ?string $emailAccentColor = null,
    ): static {
        return new self(
            companyName: $companyName ?? $this->companyName,
            companyContactEmail: $companyContactEmail ?? $this->companyContactEmail,
            phone: $phone ?? $this->phone,
            sendWindowStart: $sendWindowStart ?? $this->sendWindowStart,
            emailAccentColor: $emailAccentColor ?? $this->emailAccentColor,
        );
    }
}
