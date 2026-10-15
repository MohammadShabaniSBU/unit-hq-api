<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Setting;
use App\Models\TemplateAsset;
use App\Support\Auth\Permission;
use App\Support\Automation\RunContext;
use App\Support\Communications\EmailBlockDocument;
use App\Support\Communications\EmailBlockRenderer;
use App\Support\Communications\EmailBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GrantsSinglePermission;
use Tests\TestCase;

class EmailBrandTest extends TestCase
{
    use GrantsSinglePermission;
    use RefreshDatabase;

    public function test_defaults_match_legacy_output(): void
    {
        $path = base_path('tests/Fixtures/email-render/v2/100-nostack.json');
        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($raw);

        $brand = EmailBrand::current();
        $this->assertNull($brand->logoAssetId);
        $this->assertSame(EmailBrand::DEFAULT_TEXT, $brand->textColor);
        $this->assertSame(EmailBrand::DEFAULT_BACKGROUND, $brand->backgroundColor);
        $this->assertSame(EmailBrand::DEFAULT_FONT, $brand->fontStack);
        $this->assertSame('Arial,Helvetica,sans-serif', $brand->fontFamily());

        $out = EmailBlockRenderer::render(
            EmailBlockDocument::validate($raw),
            new RunContext,
            '#1d4ed8',
            false,
        );

        $dir = base_path('tests/Fixtures/email-render/v2');
        $this->assertStringEqualsFile($dir.'/100-nostack.html', $out['html']);
        $this->assertStringEqualsFile($dir.'/100-nostack.txt', $out['text']);
    }

    public function test_settings_return_brand_keys_and_logo_url(): void
    {
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $hash = hash('sha256', $bytes);
        $asset = TemplateAsset::query()->create([
            'hash' => $hash,
            'disk_path' => $hash.'/logo.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'created_by' => null,
        ]);

        Sanctum::actingAs($this->employeeWithPermission(Permission::SettingsManage));

        $this->patchJson('/api/settings/general', [
            'email_logo_asset_id' => $asset->id,
            'email_text_color' => '#222222',
            'email_background_color' => '#EEEEEE',
            'email_font_stack' => 'georgia',
            'email_accent_color' => '#112233',
        ])->assertOk()
            ->assertJsonPath('data.email_text_color', '#222222')
            ->assertJsonPath('data.email_font_stack', 'georgia')
            ->assertJsonPath('data.email_logo_asset_id', $asset->id)
            ->assertJsonPath('data.email_logo_url', $asset->publicUrl());

        $this->assertArrayNotHasKey('email_logo_url', Setting::general()->toArray());

        $this->patchJson('/api/settings/general', [
            'company_name' => 'Camden Lock',
        ])->assertOk()
            ->assertJsonPath('data.email_logo_asset_id', $asset->id)
            ->assertJsonPath('data.company_name', 'Camden Lock');
    }
}
