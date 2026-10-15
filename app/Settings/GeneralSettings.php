<?php

namespace App\Settings;

use App\Support\Communications\EmailBrand;
use App\Support\Time\DateFormat;

readonly class GeneralSettings implements SettingsPayload
{
    public function __construct(
        public string $companyName,
        public string $companyContactEmail,
        public string $phone,
        public string $sendWindowStart = '09:00',
        public ?string $sendWindowEnd = null,
        public string $emailAccentColor = '#1d4ed8',
        public string $dateFormat = DateFormat::DEFAULT,
        public ?int $emailLogoAssetId = null,
        public string $emailTextColor = EmailBrand::DEFAULT_TEXT,
        public string $emailBackgroundColor = EmailBrand::DEFAULT_BACKGROUND,
        public string $emailFontStack = EmailBrand::DEFAULT_FONT,
    ) {}

    public static function default(): static
    {
        return new self(
            companyName: '',
            companyContactEmail: '',
            phone: '',
            sendWindowStart: '09:00',
            sendWindowEnd: null,
            emailAccentColor: '#1d4ed8',
            dateFormat: DateFormat::DEFAULT,
            emailLogoAssetId: null,
            emailTextColor: EmailBrand::DEFAULT_TEXT,
            emailBackgroundColor: EmailBrand::DEFAULT_BACKGROUND,
            emailFontStack: EmailBrand::DEFAULT_FONT,
        );
    }

    public static function fromArray(array $data): static
    {
        $accent = is_string($data['email_accent_color'] ?? null) && $data['email_accent_color'] !== ''
            ? $data['email_accent_color']
            : '#1d4ed8';

        $logo = $data['email_logo_asset_id'] ?? null;
        $text = is_string($data['email_text_color'] ?? null) && $data['email_text_color'] !== ''
            ? $data['email_text_color']
            : EmailBrand::DEFAULT_TEXT;
        $background = is_string($data['email_background_color'] ?? null) && $data['email_background_color'] !== ''
            ? $data['email_background_color']
            : EmailBrand::DEFAULT_BACKGROUND;
        $font = is_string($data['email_font_stack'] ?? null) && in_array($data['email_font_stack'], EmailBrand::FONT_STACKS, true)
            ? $data['email_font_stack']
            : EmailBrand::DEFAULT_FONT;

        return new self(
            companyName: $data['company_name'],
            companyContactEmail: $data['company_contact_email'],
            phone: $data['phone'],
            sendWindowStart: is_string($data['send_window_start'] ?? null) && $data['send_window_start'] !== ''
                ? $data['send_window_start']
                : '09:00',
            sendWindowEnd: is_string($data['send_window_end'] ?? null) && $data['send_window_end'] !== ''
                ? $data['send_window_end']
                : null,
            emailAccentColor: $accent,
            dateFormat: DateFormat::normalize(
                is_string($data['date_format'] ?? null) ? $data['date_format'] : null
            ),
            emailLogoAssetId: is_numeric($logo) ? (int) $logo : null,
            emailTextColor: $text,
            emailBackgroundColor: $background,
            emailFontStack: $font,
        );
    }

    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'company_contact_email' => $this->companyContactEmail,
            'phone' => $this->phone,
            'send_window_start' => $this->sendWindowStart,
            'send_window_end' => $this->sendWindowEnd,
            'email_accent_color' => $this->emailAccentColor,
            'date_format' => $this->dateFormat,
            'email_logo_asset_id' => $this->emailLogoAssetId,
            'email_text_color' => $this->emailTextColor,
            'email_background_color' => $this->emailBackgroundColor,
            'email_font_stack' => $this->emailFontStack,
        ];
    }

    public function with(
        ?string $companyName = null,
        ?string $companyContactEmail = null,
        ?string $phone = null,
        ?string $sendWindowStart = null,
        mixed $sendWindowEnd = false,
        ?string $emailAccentColor = null,
        ?string $dateFormat = null,
        mixed $emailLogoAssetId = false,
        ?string $emailTextColor = null,
        ?string $emailBackgroundColor = null,
        ?string $emailFontStack = null,
    ): static {
        return new self(
            companyName: $companyName ?? $this->companyName,
            companyContactEmail: $companyContactEmail ?? $this->companyContactEmail,
            phone: $phone ?? $this->phone,
            sendWindowStart: $sendWindowStart ?? $this->sendWindowStart,
            sendWindowEnd: $sendWindowEnd === false ? $this->sendWindowEnd : (is_string($sendWindowEnd) && $sendWindowEnd !== '' ? $sendWindowEnd : null),
            emailAccentColor: $emailAccentColor ?? $this->emailAccentColor,
            dateFormat: $dateFormat !== null ? DateFormat::normalize($dateFormat) : $this->dateFormat,
            emailLogoAssetId: $emailLogoAssetId === false
                ? $this->emailLogoAssetId
                : (is_numeric($emailLogoAssetId) ? (int) $emailLogoAssetId : null),
            emailTextColor: $emailTextColor ?? $this->emailTextColor,
            emailBackgroundColor: $emailBackgroundColor ?? $this->emailBackgroundColor,
            emailFontStack: $emailFontStack ?? $this->emailFontStack,
        );
    }
}
