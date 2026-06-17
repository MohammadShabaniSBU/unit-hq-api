<?php

namespace App\Settings;

readonly class GeneralSettings implements SettingsPayload
{
    public function __construct(
        public string $companyName,
        public string $companyContactEmail,
        public string $phone,
    ) {}

    public static function default(): static
    {
        return new self(
            companyName: '',
            companyContactEmail: '',
            phone: '',
        );
    }

    public static function fromArray(array $data): static
    {
        return new self(
            companyName: $data['company_name'],
            companyContactEmail: $data['company_contact_email'],
            phone: $data['phone'],
        );
    }

    public function toArray(): array
    {
        return [
            'company_name'          => $this->companyName,
            'company_contact_email' => $this->companyContactEmail,
            'phone'                 => $this->phone,
        ];
    }

    public function with(
        ?string $companyName = null,
        ?string $companyContactEmail = null,
        ?string $phone = null,
    ): static {
        return new self(
            companyName: $companyName ?? $this->companyName,
            companyContactEmail: $companyContactEmail ?? $this->companyContactEmail,
            phone: $phone ?? $this->phone,
        );
    }
}
