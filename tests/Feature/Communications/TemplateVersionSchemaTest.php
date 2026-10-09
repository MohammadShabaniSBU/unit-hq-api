<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Enums\TemplateVersionStatus;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateVersionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_helper_creates_one_published_version(): void
    {
        $family = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Versioned',
            'purpose' => TemplatePurpose::General,
        ], variants: [[
            'locale' => 'en',
            'subject' => 'Hello',
            'legacy_html' => '<p>Hi</p>',
        ]]);

        $this->assertSame(1, $family->versions()->count());
        $this->assertNull($family->draft);

        $current = $family->currentVersion;
        $this->assertNotNull($current);
        $this->assertSame(1, $current->version_number);
        $this->assertSame(TemplateVersionStatus::Published, $current->status);
        $this->assertNotNull($current->published_at);

        $variant = $current->variants()->firstOrFail();
        $this->assertSame($current->id, $variant->template_version_id);
        $this->assertSame($family->id, $variant->template_family_id);
        $this->assertSame('en', $variant->locale);
    }
}
