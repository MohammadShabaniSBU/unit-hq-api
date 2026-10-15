<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Models\TemplateAsset;

final class SocialIcon
{
    /** @var list<string> */
    public const NETWORKS = [
        'facebook',
        'instagram',
        'linkedin',
        'x',
        'youtube',
        'tiktok',
        'website',
    ];

    public static function filename(string $network): string
    {
        return 'social-'.$network.'.png';
    }

    public static function url(string $network): ?string
    {
        $asset = TemplateAsset::query()
            ->where('original_filename', self::filename($network))
            ->whereNull('created_by')
            ->first();

        return $asset instanceof TemplateAsset ? $asset->publicUrl() : null;
    }
}
