<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TemplateAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateAssetController extends Controller
{
    private const MAX_BYTES = 5_242_880; // 5 MiB

    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ceil(self::MAX_BYTES / 1024), 'mimes:jpg,jpeg,png,gif,webp'],
        ]);

        $file = $request->file('file');
        if ($file === null) {
            return $this->error('File is required.', ['file' => ['File is required.']], 422);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->error('File exceeds the maximum size.', [
                'file' => ['Maximum size is '.self::MAX_BYTES.' bytes.'],
            ], 422);
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            return $this->error('Unsupported image type.', [
                'file' => ['Allowed types: jpeg, png, gif, webp.'],
            ], 422);
        }

        $contents = file_get_contents($file->getRealPath()) ?: '';
        $hash = hash('sha256', $contents);

        $existing = TemplateAsset::query()->where('hash', $hash)->first();
        if ($existing instanceof TemplateAsset) {
            return $this->created([
                'id' => $existing->id,
                'hash' => $existing->hash,
                'original_filename' => $existing->original_filename,
                'mime_type' => $existing->mime_type,
                'size_bytes' => $existing->size_bytes,
                'public_url' => $existing->publicUrl(),
            ], 'Template asset already exists.');
        }

        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
        $extension = $file->getClientOriginalExtension();
        $filename = $extension !== '' ? $safeName.'.'.$extension : $safeName;
        $path = $hash.'/'.$filename;

        Storage::disk('template-assets')->put($path, $contents);

        $asset = TemplateAsset::query()->create([
            'hash' => $hash,
            'disk_path' => $path,
            'original_filename' => $file->getClientOriginalName() !== ''
                ? $file->getClientOriginalName()
                : $filename,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'created_by' => $request->user()?->id,
        ]);

        return $this->created([
            'id' => $asset->id,
            'hash' => $asset->hash,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
            'public_url' => $asset->publicUrl(),
        ], 'Template asset uploaded.');
    }

    public function destroy(TemplateAsset $templateAsset): JsonResponse
    {
        if ($templateAsset->isReferenced()) {
            return $this->error(__('errors.templates.asset_in_use'), [
                'asset' => [__('errors.templates.asset_in_use')],
            ], 422);
        }

        Storage::disk('template-assets')->delete($templateAsset->disk_path);
        $templateAsset->delete();

        return $this->noContent('Template asset deleted.');
    }

    public function showPublic(string $hash, string $filename): StreamedResponse|JsonResponse
    {
        $asset = TemplateAsset::query()->where('hash', $hash)->first();
        if ($asset === null) {
            return $this->notFound('Asset not found.');
        }

        $disk = Storage::disk('template-assets');
        if (! $disk->exists($asset->disk_path)) {
            return $this->notFound('Asset not found.');
        }

        return $disk->response(
            $asset->disk_path,
            $asset->original_filename,
            [
                'Content-Type' => $asset->mime_type,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
        );
    }
}
