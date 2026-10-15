<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Enums\TemplateChannel;
use App\Enums\TemplatePurpose;
use App\Models\TemplateFamily;
use App\Support\Communications\EmailBlockDocument;
use Database\Factories\TemplateFamilyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TemplateFamilyFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function legacy_v1_state(): void
    {
        $legacy = TemplateFamily::factory()->legacyV1()->publish([
            'channel' => TemplateChannel::Email,
            'name' => 'Legacy letter',
            'purpose' => TemplatePurpose::General,
        ], [[
            'locale' => 'en',
            'subject' => 'Hi',
        ]]);

        $blocks = $legacy->currentVersion?->variants()->firstOrFail()->blocks;
        $this->assertIsArray($blocks);
        $this->assertSame(1, $blocks['version']);
        $this->assertSame('<p>Hello</p>', $blocks['blocks'][0]['params']['html']);

        $upcast = EmailBlockDocument::upcast($blocks);
        $this->assertSame(2, $upcast['version']);
        $this->assertSame(
            '<p>Hello</p>',
            $upcast['sections'][0]['columns'][0]['blocks'][0]['params']['html'],
        );

        $modern = TemplateFamilyFactory::published([
            'channel' => TemplateChannel::Email,
            'name' => 'Modern letter',
            'purpose' => TemplatePurpose::General,
        ], [[
            'locale' => 'en',
            'subject' => 'Hi',
        ]]);

        $document = $modern->currentVersion?->variants()->firstOrFail()->blocks;
        $this->assertIsArray($document);
        $this->assertSame(2, $document['version']);
        $this->assertSame('100', $document['sections'][0]['layout']);
        $this->assertSame(
            '<p>Hello</p>',
            $document['sections'][0]['columns'][0]['blocks'][0]['params']['html'],
        );
    }
}
