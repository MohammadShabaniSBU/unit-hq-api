<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TemplateAsset;
use App\Support\Communications\SocialIcon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * System-owned social icons. created_by stays null so rendered email
 * references these rows, never a third-party CDN.
 */
class SocialIconSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SocialIcon::NETWORKS as $network) {
            $source = resource_path('template-assets/social/'.$network.'.png');
            $contents = file_get_contents($source);
            if ($contents === false) {
                continue;
            }

            $filename = SocialIcon::filename($network);
            $hash = hash('sha256', $contents);
            $diskPath = $hash.'/'.$filename;

            Storage::disk('template-assets')->put($diskPath, $contents);

            TemplateAsset::query()->firstOrCreate(
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
}
