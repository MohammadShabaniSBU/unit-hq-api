<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\Employee;
use App\Models\TemplateAsset;
use App\Models\TemplateFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_serve_reference_guard(): void
    {
        Storage::fake('template-assets');

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $file = UploadedFile::fake()->image('logo.png', 40, 40);

        $upload = $this->post('/api/template-assets', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ]);
        $upload->assertCreated();
        $assetId = (int) $upload->json('data.id');
        $hash = (string) $upload->json('data.hash');
        $publicUrl = (string) $upload->json('data.public_url');
        $this->assertStringContainsString('/api/public/template-assets/'.$hash.'/', $publicUrl);

        $asset = TemplateAsset::query()->findOrFail($assetId);
        Storage::disk('template-assets')->assertExists($asset->disk_path);

        $serve = $this->get('/api/public/template-assets/'.$hash.'/'.rawurlencode($asset->original_filename));
        $serve->assertOk();
        $this->assertStringStartsWith('image/', (string) $serve->headers->get('Content-Type'));

        $family = TemplateFamily::query()->create([
            'channel' => TemplateChannel::Email,
            'name' => 'With image',
            'purpose' => TemplatePurpose::General,
        ]);
        $family->variants()->create([
            'locale' => 'en',
            'subject' => 'Hi',
            'blocks' => [
                'version' => 1,
                'blocks' => [[
                    'id' => 'i1',
                    'type' => 'image',
                    'params' => [
                        'asset_id' => $assetId,
                        'alt' => 'Logo',
                        'width_percent' => 100,
                    ],
                ]],
            ],
        ]);

        $blocked = $this->deleteJson('/api/template-assets/'.$assetId);
        $blocked->assertStatus(422);

        // Clear reference then delete succeeds.
        $family->variants()->firstOrFail()->update(['blocks' => null]);
        $this->deleteJson('/api/template-assets/'.$assetId)->assertNoContent();
        $this->assertNull(TemplateAsset::query()->find($assetId));
    }
}
