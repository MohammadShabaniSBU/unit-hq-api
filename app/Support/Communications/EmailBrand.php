<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\Setting;
use App\Models\TemplateAsset;
use App\Settings\GeneralSettings;

final readonly class EmailBrand
{
    public const DEFAULT_TEXT = '#111827';

    public const DEFAULT_BACKGROUND = '#F3F4F6';

    public const DEFAULT_FONT = 'arial';

    /** @var list<string> */
    public const FONT_STACKS = [
        'system',
        'arial',
        'georgia',
        'verdana',
        'trebuchet',
    ];

    public function __construct(
        public ?int $logoAssetId,
        public string $textColor,
        public string $backgroundColor,
        public string $fontStack,
        public string $accentColor,
    ) {}

    public static function current(): self
    {
        return self::fromSettings(Setting::general());
    }

    public static function fromSettings(GeneralSettings $settings): self
    {
        return new self(
            logoAssetId: $settings->emailLogoAssetId,
            textColor: $settings->emailTextColor,
            backgroundColor: $settings->emailBackgroundColor,
            fontStack: $settings->emailFontStack,
            accentColor: $settings->emailAccentColor,
        );
    }

    public function fontFamily(): string
    {
        return match ($this->fontStack) {
            'georgia' => 'Georgia,Times New Roman,Times,serif',
            'verdana' => 'Verdana,Geneva,sans-serif',
            'trebuchet' => "'Trebuchet MS',Helvetica,sans-serif",
            'system' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
            default => 'Arial,Helvetica,sans-serif',
        };
    }

    public function usesLegacyFont(): bool
    {
        return $this->fontStack === self::DEFAULT_FONT;
    }

    public function usesLegacyText(): bool
    {
        return strtolower($this->textColor) === strtolower(self::DEFAULT_TEXT);
    }

    public function logoUrl(): ?string
    {
        if ($this->logoAssetId === null) {
            return null;
        }

        $asset = TemplateAsset::query()->find($this->logoAssetId);

        return $asset instanceof TemplateAsset ? $asset->publicUrl() : null;
    }
}
