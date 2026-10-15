<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Setting;
use App\Models\TemplateAsset;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo email brand: colours, font, and a committed logo asset.
 * Idempotent. No random draws.
 */
final class DemoBrand
{
    public const ACCENT = '#0F6E56';

    public const TEXT = '#1F2937';

    public const BACKGROUND = '#F7F5F2';

    public const FONT = 'georgia';

    public static function apply(): TemplateAsset
    {
        $asset = self::logo();

        Setting::setGeneral(Setting::general()->with(
            emailAccentColor: self::ACCENT,
            emailTextColor: self::TEXT,
            emailBackgroundColor: self::BACKGROUND,
            emailFontStack: self::FONT,
            emailLogoAssetId: $asset->id,
        ));

        return $asset;
    }

    private static function logo(): TemplateAsset
    {
        $source = __DIR__.'/assets/logo.png';
        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new RuntimeException("Demo logo missing at {$source}.");
        }

        $filename = 'logo.png';
        $hash = hash('sha256', $contents);
        $diskPath = $hash.'/'.$filename;

        Storage::disk('template-assets')->put($diskPath, $contents);

        return TemplateAsset::query()->firstOrCreate(
            ['hash' => $hash],
            [
                'disk_path' => $diskPath,
                'original_filename' => $filename,
                'mime_type' => 'image/png',
                'size_bytes' => strlen($contents),
                'created_by' => null,
            ],
        );
    }
}
